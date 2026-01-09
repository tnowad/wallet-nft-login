(function () {
    const CONFIG = window.WPWN_CONFIG || {};
    const CONTROLLERS = [];

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-wpwn-login-button]').forEach((button) => {
            const root = button.closest('.wpwn-login');
            if (!root) {
                return;
            }
            CONTROLLERS.push(new WalletLoginController(root));
        });
    });

    class WalletLoginController {
        constructor(root) {
            this.root = root;
            this.button = root.querySelector('[data-wpwn-login-button]');
            this.statusEl = root.querySelector('.wpwn-login__status');
            this.isBusy = false;
            if (this.button) {
                this.button.addEventListener('click', () => this.handleClick());
            }
        }

        async handleClick() {
            if (this.isBusy) {
                return;
            }

            this.setStatus('Requesting wallet...', '');
            this.toggleBusy(true);

            try {
                const session = await this.connectAndSign();
                const verification = await this.verify(session);
                this.setStatus('Success! Redirecting...', 'wpwn-login__status--success');
                window.setTimeout(() => {
                    window.location.assign(verification.redirect || CONFIG.loginRedirect || window.location.origin);
                }, 500);
            } catch (error) {
                console.error('Wallet login failed', error);
                const message = error && error.message ? error.message : 'Login was cancelled.';
                this.setStatus(message, 'wpwn-login__status--error');
            } finally {
                this.toggleBusy(false);
            }
        }

        async connectAndSign() {
            const noncePayload = await fetch(`${CONFIG.restBase}/nonce`, { credentials: 'same-origin' })
                .then(checkResponse);

            const connector = await selectConnector();
            const wallet = await connector.connect();
            const domain = noncePayload.domain || window.location.hostname;
            const chainIdDecimal = normalizeChainId(wallet.chainId || chainKeyToChainId(CONFIG.defaultChain));
            const message = buildSiweMessage({
                domain,
                address: wallet.address,
                nonce: noncePayload.nonce,
                chainId: chainIdDecimal,
                uri: window.location.origin,
            });

            const signature = await connector.signMessage(message, wallet.address);

            return { message, signature };
        }

        async verify(payload) {
            return fetch(`${CONFIG.restBase}/verify`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': CONFIG.restNonce || '',
                },
                body: JSON.stringify(payload),
            }).then(checkResponse);
        }

        setStatus(message, className) {
            if (!this.statusEl) {
                return;
            }
            this.statusEl.classList.remove('wpwn-login__status--error', 'wpwn-login__status--success');
            if (className) {
                this.statusEl.classList.add(className);
            }
            this.statusEl.textContent = message;
        }

        toggleBusy(state) {
            this.isBusy = state;
            if (this.button) {
                this.button.disabled = state;
            }
        }
    }

    function buildSiweMessage({ domain, address, nonce, chainId, uri, statement }) {
        const now = new Date().toISOString();
        const body = [
            `${domain} wants you to sign in with your Ethereum account:`,
            address,
            '',
            statement || 'Sign-in request for WordPress.',
            '',
            `URI: ${uri}`,
            'Version: 1',
            `Chain ID: ${chainId}`,
            `Nonce: ${nonce}`,
            `Issued At: ${now}`,
        ];
        return body.join('\n');
    }

    async function selectConnector() {
        const providers = CONFIG.providers || [];
        for (const providerKey of providers) {
            try {
                if (providerKey === 'ramper' && CONFIG.ramper && CONFIG.ramper.appId) {
                    const connector = new RamperConnector(CONFIG.ramper);
                    await connector.assertReady();
                    return connector;
                }

                if (providerKey === 'walletconnect' && CONFIG.walletConnect && CONFIG.walletConnect.projectId) {
                    const connector = new WalletConnectConnector(CONFIG.walletConnect);
                    await connector.assertReady();
                    return connector;
                }

                if (providerKey === 'metamask' && window.ethereum) {
                    return new BrowserWalletConnector();
                }
            } catch (error) {
                console.warn(`Provider ${providerKey} failed during discovery`, error);
            }
        }

        if (window.ethereum) {
            return new BrowserWalletConnector();
        }

        throw new Error('No supported wallet providers are available.');
    }

    class BrowserWalletConnector {
        constructor() {
            this.provider = window.ethereum;
        }

        async connect() {
            if (!this.provider) {
                throw new Error('No EIP-1193 provider detected.');
            }

            const accounts = await this.provider.request({ method: 'eth_requestAccounts' });
            if (!accounts || !accounts.length) {
                throw new Error('No accounts returned by wallet.');
            }

            const chainId = await this.provider.request({ method: 'eth_chainId' });
            return {
                address: accounts[0],
                chainId,
                provider: this.provider,
            };
        }

        async signMessage(message, address) {
            return requestSignature(this.provider, message, address);
        }

        async assertReady() {
            if (!this.provider) {
                throw new Error('window.ethereum unavailable');
            }
        }
    }

    class RamperConnector {
        constructor(config) {
            this.config = config;
            this.provider = null;
        }

        async assertReady() {
            if (this.provider) {
                return;
            }

            if (!window.Ramper) {
                await loadScript('https://cdn.ramper.xyz/sdk/latest/ramper.min.js');
            }

            if (window.Ramper && typeof window.Ramper.init === 'function') {
                this.provider = await window.Ramper.init({
                    appId: this.config.appId,
                    chainName: this.config.environment || 'mainnet',
                });
            } else if (window.Ramper && typeof window.Ramper.getProvider === 'function') {
                this.provider = await window.Ramper.getProvider({
                    appId: this.config.appId,
                    chainName: this.config.environment || 'mainnet',
                });
            } else if (window.ramper && window.ramper.provider) {
                this.provider = window.ramper.provider;
            }

            if (!this.provider) {
                throw new Error('Ramper SDK is not available.');
            }
        }

        async connect() {
            await this.assertReady();
            const accounts = await this.provider.request({ method: 'eth_requestAccounts' });
            if (!accounts || !accounts.length) {
                throw new Error('Ramper did not return an account.');
            }
            const chainId = await this.provider.request({ method: 'eth_chainId' });
            return {
                address: accounts[0],
                chainId,
                provider: this.provider,
            };
        }

        async signMessage(message, address) {
            return requestSignature(this.provider, message, address);
        }
    }

    class WalletConnectConnector {
        constructor(config) {
            this.config = config;
            this.provider = null;
            this.chainId = chainKeyToChainId(CONFIG.defaultChain);
        }

        async assertReady() {
            if (this.provider) {
                return;
            }

            if (!window.WalletConnectProvider) {
                await loadScript('https://unpkg.com/@walletconnect/ethereum-provider@2/dist/umd/index.min.js');
            }

            if (!window.WalletConnectProvider) {
                throw new Error('WalletConnect provider library failed to load.');
            }

            this.provider = await window.WalletConnectProvider.init({
                projectId: this.config.projectId,
                chains: [this.chainId],
                showQrModal: true,
            });
        }

        async connect() {
            await this.assertReady();
            const accounts = await this.provider.enable();
            if (!accounts || !accounts.length) {
                throw new Error('WalletConnect did not return an account.');
            }
            const chainId = await this.provider.request({ method: 'eth_chainId' });
            return {
                address: accounts[0],
                chainId,
                provider: this.provider,
            };
        }

        async signMessage(message, address) {
            return requestSignature(this.provider, message, address);
        }
    }

    async function loadScript(src) {
        if (document.querySelector(`script[src="${src}"]`)) {
            return;
        }

        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = resolve;
            script.onerror = () => reject(new Error(`Failed to load ${src}`));
            document.body.appendChild(script);
        });
    }

    function requestSignature(provider, message, address) {
        const target = address || provider.selectedAddress;
        return provider.request({ method: 'personal_sign', params: [message, target] })
            .catch(() => {
                if (!target) {
                    throw new Error('retry');
                }
                return provider.request({ method: 'personal_sign', params: [target, message] });
            })
            .catch(async () => {
                const accounts = await provider.request({ method: 'eth_accounts' });
                const account = accounts && accounts.length ? accounts[0] : null;
                if (!account) {
                    throw new Error('No active account for signature.');
                }
                return provider.request({ method: 'personal_sign', params: [message, account] });
            });
    }

    function normalizeChainId(value) {
        if (!value) {
            return 1;
        }
        if (typeof value === 'number') {
            return value;
        }
        if (typeof value === 'string' && value.startsWith('0x')) {
            return parseInt(value, 16);
        }
        return parseInt(value, 10) || 1;
    }

    function chainKeyToChainId(key) {
        const map = {
            'ethereum-mainnet': 1,
            'ethereum-goerli': 5,
            'ethereum-sepolia': 11155111,
            'polygon-mainnet': 137,
            'polygon-mumbai': 80001,
        };
        return map[key] || 1;
    }

    async function checkResponse(response) {
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body && body.message ? body.message : 'Unexpected response from server.');
        }

        return response.json();
    }
})();
