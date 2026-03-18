=== AI Provider for mittwald ===
Contributors: mittwald, lukasfritzedev, mhelmich
Tags: AI, llm, gpt, artificial-intelligence, connector
Requires at least: 6.9
Tested up to: 7.0-beta5
Stable tag: trunk
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WordPress AI to mittwald AI Hosting, enabling AI-powered features via an OpenAI-compatible provider integration.

== Description ==

This plugin integrates [mittwald AI Hosting](https://developer.mittwald.de/docs/v2/platform/aihosting/) with WordPress AI features, enabling AI-powered features on your WordPress site using mittwald's infrastructure.

This plugin requires WordPress 7.0 or newer, including WordPress 7.0 pre-releases.

== Supported Operations & Models ==

= Chat Completions =

Fully supported for conversational AI, content generation, and chat-based interactions.

**Available Models:**
- **GPT-OSS models**: Open-source GPT-compatible models
- **Ministral**: supports vision/image input
- **Devstral**: optimized for code generation

**Capabilities:**
- Standard text chat
- Image vision (Mistral Small models only)
- JSON output formatting
- Tool/function calling
- Streaming responses

== Installation ==

Install this plugin:

* Install the plugin in the WordPress Dashboard or
* Download the plugin files and upload it manually to the server
* Activate the plugin

Alternatively, use the WP CLI to install this plugin:

`wp plugin install --activate mittwald-ai-provider`

= Configuration =

1. **Obtain an API Key**: Follow the [mittwald AI Hosting access guide](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/) to get your API credentials.
2. **Store AI Client Credentials** (choose one):
    - In WordPress admin:
      - Navigate to Settings > Connectors (`/wp-admin/options-connectors.php`)
      - Fill in the mittwald API key and save
    - In `wp-config.php` via WP-CLI: `wp config set MITTWALD_API_KEY your-api-key`
    - In `wp-config.php` via direct file edit: `define( 'MITTWALD_API_KEY', 'your-api-key' );`
    - As environment variable (for example in Apache config): `SetEnv MITTWALD_API_KEY your-api-key`
3. **Enable AI experiments** (optional):
    - To actually use the connector, you need a plugin that makes use of the AI connector. The Plugin [AI Experiments](https://wordpress.org/plugins/ai/) is the official example. Install and activate the plugin.
    - Navigate to Settings > AI Experiments (`/options-general.php?page=ai-experiments`)
    - Select »Enable Experiments« and Save
    - Select the Experiments you want to use and Save

== Frequently Asked Questions ==

= How do I get a mittwald AI hosting API key? =

To obtain an API Key follow the [mittwald AI Hosting access guide](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/) to get your API credentials.

= Does mittwald AI hosting have rate Limits or usage quotas? =

mittwald AI Hosting has usage limits based on your account tier. For details on usage limits and terms, see the [mittwald AI Hosting terms of use](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/).

== Screenshots ==

1. AI Connectors settings page where you can enter your mittwald AI Hosting API key.
