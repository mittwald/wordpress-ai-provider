=== mittwald AI provider ===
Contributors: mittwald, lukasfritzedev, mhelmich
Tags: AI, llm
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WordPress AI Experiments to mittwald AI Hosting, enabling AI-powered features via an OpenAI-compatible provider integration.

== Description ==

This plugin integrates [mittwald AI Hosting](https://developer.mittwald.de/docs/v2/platform/aihosting/) with the [WordPress AI Experiments Plugin](https://wordpress.org/plugins/ai/), enabling AI-powered features on your WordPress site using mittwald's infrastructure.

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

Make sure the Plugin [AI Experiments](https://wordpress.org/plugins/ai/) is installed. If not, install and activate it.

Install this plugin:

* Install the plugin in the WordPress Dashboard or
* Download the plugin files and upload it manually to the server
* Activate the plugin

= Configuration =

1. **Obtain an API Key**: Follow the [mittwald AI Hosting access guide](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/) to get your API credentials.
2. **Store AI Client Credentials**:
    - Navigate to Settings > AI Credentials (`/wp-admin/options-general.php?page=wp-ai-client`)
    - Fill in the mittwald API key and save
3. **Enable AI experiments**:
    - Navigate to Settings > AI Experiments (`/options-general.php?page=ai-experiments`)
    - Select »Enable Experiments« and Save
    - Select the Experiments you want to use and Save

== Frequently Asked Questions ==

= How do I get a mittwald AI hosting API key? =

To obtain an API Key follow the [mittwald AI Hosting access guide](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/) to get your API credentials.

= Does mittwald AI hosting have rate Limits or usage quotas? =

mittwald AI Hosting has usage limits based on your account tier. For details on usage limits and terms, see the [mittwald AI Hosting terms of use](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/).

== Screenshots ==

1. AI Credentials settings page where you can enter your mittwald AI Hosting API key.
