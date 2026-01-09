# Wallet NFT Login

Wallet NFT Login is a WordPress plugin that replaces email/password authentication with Sign-In with Ethereum (SIWE) and exposes helper utilities for NFT-aware experiences. Phase 1 focuses on wallet-based authentication while Phase 2 adds read-only NFT awareness that can be expanded later for minting or gated content.

## Feature overview

- **Wallet-first login**: Adds “Login with Wallet” buttons to the default `/wp-login.php` screen and via `[wallet_login_button]` shortcode for any frontend location.
- **SIWE-compliant flow**: Secure nonce issuance, replay protection, and on-chain signature recovery implemented entirely in PHP (no SaaS dependency).
- **Provider flexibility**: Built-in connectors for Ramper, WalletConnect v2, and any EIP-1193 browser wallet (MetaMask, Brave, Rabby, etc.).
- **User mapping**: Wallet addresses become the canonical identifier; the plugin creates lightweight WordPress users (no usable password) on first login.
- **Optional password lockout**: When enabled, wallet-linked users cannot fall back to passwords.
- **NFT awareness**: Configure ERC-721/ERC-1155 contracts per chain and query ownership via helper functions or REST.
- **Chain config ready**: Admins can add/update RPC endpoints without redeploying the plugin. No contract knowledge is required during installation.
- **Future-proof**: Clear separation between auth, chain configuration, and NFT utilities so minting/gating modules can be added later.

## Installation

1. Copy the `wallet-nft-login` folder into `wp-content/plugins/` (Composer dependencies are already vendored).
2. Activate *Wallet NFT Login* from **Plugins → Installed Plugins**.
3. Visit **Settings → Wallet Login** to configure providers and RPC endpoints.

## Admin settings

| Setting | Purpose |
| --- | --- |
| Enabled providers | Choose which connectors are exposed to users (Ramper, WalletConnect, MetaMask/EIP-1193). |
| Disable passwords | When checked, wallet-linked accounts cannot authenticate via username/password. |
| Ramper App ID & Environment | Paste the project/app ID from Ramper and target environment (`mainnet`, `testnet`, or custom). The plugin loads `https://cdn.ramper.xyz/sdk/latest/ramper.min.js` automatically. |
| WalletConnect Project ID | Required for WalletConnect v2 QR modal. Obtain from https://cloud.walletconnect.com. |
| Default chain key | Reference that ties wallets, RPC endpoints, and NFT configs together (`ethereum-mainnet`, `ethereum-sepolia`, `polygon-mainnet`, etc.). |
| RPC endpoints JSON | JSON object where keys are chain identifiers and values are HTTPS RPC URLs (Infura, Alchemy, Ankr, custom). Example:
```json
{
  "ethereum-mainnet": "https://mainnet.infura.io/v3/XXX",
  "ethereum-sepolia": "https://sepolia.infura.io/v3/YYY",
  "polygon-mainnet": "https://polygon-mainnet.g.alchemy.com/v2/ZZZ"
}
```
| NFT contracts JSON | Optional array describing NFTs to monitor. Example:
```json
[
  {
    "label": "Genesis Pass",
    "address": "0x1234...",
    "type": "erc721",
    "chain": "ethereum-mainnet"
  },
  {
    "label": "Event Ticket",
    "address": "0xabcd...",
    "type": "erc1155",
    "chain": "polygon-mainnet",
    "tokenId": "42"
  }
]
```

## Frontend usage

- **Login button shortcode**: Place `[wallet_login_button]` anywhere content is rendered. Optional attributes:
  - `label`: custom button text.
  - `class`: extra CSS classes.
- **Custom redirect**: hook into `add_filter( 'wpwn_login_redirect', fn( $url, $user_id, $address ) => '/dashboard' );` to change the post-login destination.
- **Styles**: Minimal CSS is bundled (`assets/css/login.css`). Extend or override as needed.

## Helper functions

These functions are available to themes/plugins after activation:

```php
wpwn_get_wallet_for_user( $user_id = null ); // returns normalized wallet address or null
wpwn_current_user_has_nft( $contract, $tokenId = null, $type = 'erc721', $chain = null );
wpwn_current_user_has_any_nft( $contract, $chain = null );
```

Behind the scenes `wpwn_current_user_has_nft()` performs on-demand `eth_call` requests against the configured RPC endpoint, caching each result per user/contract for one minute.

## REST endpoints

All routes live under `wp-json/wpwn/v1/`:

- `GET /nonce` – issues a SIWE nonce plus site domain and default chain key.
- `POST /verify` – validates the signed SIWE payload, links/creates the WordPress user, and issues cookies.
- `GET /nfts` – (authenticated) returns configured contract ownership snapshot for the current user.

## Security considerations

- **Nonce & replay**: Nonces expire after 10 minutes and are single-use.
- **Signature recovery**: Implemented locally using phpseclib3 and keccak hashing—no external verifier.
- **Password lockout**: Enabled by default to keep wallet-only identities clean; disable temporarily if you need to migrate users.
- **Transport**: REST calls rely on your site’s TLS; always serve WordPress over HTTPS before exposing wallet login.
- **RPC isolation**: Provide your own Infura/Alchemy/Ankr credentials. The plugin never hardcodes node URLs.

## Ramper, WalletConnect, MetaMask

- **Ramper**: Supply an App ID + environment. The plugin lazy-loads the Ramper SDK and calls `Ramper.init()`/`Ramper.getProvider()` to obtain an EIP-1193 provider. Future SDK changes can be supported by swapping the connector class only.
- **WalletConnect**: Requires a v2 project ID. Users on desktop/mobile can scan the QR modal automatically triggered by `@walletconnect/ethereum-provider`.
- **MetaMask / browser wallets**: Falls back to `window.ethereum` if other providers fail or are disabled. Any EIP-1193 compatible extension works.

## Extensibility roadmap

- **Minting**: Keep mint logic in separate modules. The existing REST scaffolding already separates authentication, chain config, and NFT utilities so you can add `/mint` later without touching login.
- **Gated content**: Combine `wpwn_current_user_has_nft()` with hooks like `template_redirect` or block visibility rules for on-chain access control.
- **Multiple chains**: Add more entries to RPC JSON and reference their keys in NFT definitions. The SIWE flow remains chain agnostic as long as the wallet returns the correct `chainId`.

## Troubleshooting

- **Provider missing**: Ensure the provider is enabled in settings and any required IDs are populated. Check the browser console for “Provider ... failed during discovery.”
- **Signature mismatch**: Clear browser caches and confirm the site URL (domain) matches what users see—the SIWE message embeds the host and must match.
- **RPC errors**: Use the browser dev tools network tab to inspect failed `eth_call` responses. Rate limits or invalid URLs will surface here.

## Development notes

- Dependencies are locked via `composer.lock`; run `composer install` inside the plugin directory if you need to rebuild vendor assets.
- JavaScript is framework-free and intentionally small so it can run on the WordPress login screen without bundling.
- PHP 8.0+ is required for typed properties and `str_starts_with`.
