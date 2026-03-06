# mittwald AI Provider for WordPress

> [!WARNING]
> This plugin is experimental and not (yet) recommended for production usage.

A WordPress plugin that integrates
[mittwald AI Hosting](https://developer.mittwald.de/docs/v2/platform/aihosting/) 
with the [WordPress AI Experiments Plugin](https://github.com/WordPress/ai), 
enabling AI-powered features on your WordPress site using mittwald's 
infrastructure.

## About
This plugin provides a mittwald provider implementation for the
[WordPress AI Experiments Plugin](https://github.com/WordPress/ai), allowing you
to leverage mittwald's AI hosting platform for various AI Experiments.

**Credit**: This provider implementation is forked from the 
[`OpenAIProvider` in `php-ai-client`](https://github.com/WordPress/php-ai-client/tree/trunk/src/ProviderImplementations/OpenAi) and adapted for mittwald's AI hosting platform, which uses an OpenAI-compatible API.

## Requirements
- WordPress 6.9 or higher
- [WordPress AI Experiments Plugin](https://github.com/WordPress/ai) (when using WordPress 6.9)
- A [mittwald AI Hosting](https://www.mittwald.de/mstudio/ai-hosting) account with API access

## Installation

If you are using WordPress 6.9, make sure the Plugin [AI Experiments](https://wordpress.org/plugins/ai/) is installed. If it is not installed, install and activate it. On WordPress 7.0 and later, the necessary features are included in core, so you can skip this step.

Install this plugin:

* Download this plugin from the WordPress plugin directory as a ZIP file
* Upload to WordPress
  * Navigate to "Plugins" → "Add New" (`/wp-admin/plugin-install.php`) 
    and click "Upload Plugin".
  * Choose the downloaded ZIP file and click "Install Now".
* Activate the Plugin

Alternatively, use the WP CLI to install this plugin:

```shellsession
$ wp plugin install --activate mittwald-ai-provider`
```

## Configuration
1. **Obtain an API Key**: Follow the [mittwald AI Hosting access guide](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/) to get your API credentials.
2. **Store AI Client Credentials**:
    - **WordPress 6.9 only**: Navigate to Settings > AI Credentials (`/wp-admin/options-general.php?page=wp-ai-client`)
    - **WordPRess 7.0 and later**: Savigate to Settings > Connectors (`/wp-admin/options-connectors.php`)
    - Fill in the mittwald API key and save
3. **Enable AI experiments** (WordPress 6.9 only):
    - Navigate to Settings > AI Experiments (`/options-general.php?page=ai-experiments`)
    - Select »Enable Experiments« and Save
    - Select the Experiments you want to use and Save

## Supported Operations & Models

### Chat Completions ✅
Fully supported for conversational AI, content generation, and chat-based interactions.

**Available Models:**
- **GPT-OSS models**: Open-source GPT-compatible models
- **Ministral**: supports vision/image input
- **Devstral**: optimized for code generation

- **Capabilities:**
- Standard text chat
- Image vision (Mistral Small models only)
- JSON output formatting
- Tool/function calling
- Streaming responses

### Embeddings ⏸️

Not currently implemented.

### Text to Image ⏸️

Not currently supported by mittwald AI Hosting.

### Text to Speech ⏸️

Not currently supported by mittwald AI Hosting.

### Speech to Text / Audio Transcription ⏸️

Not currently implemented.

### Moderation ⏸️

Not currently supported by mittwald AI Hosting.

## Rate Limits & Quotas

mittwald AI Hosting has usage limits based on your account tier.
For details on usage limits and terms, see the [mittwald AI Hosting terms of use](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/).

## Resources

- **mittwald AI Hosting Documentation**: https://developer.mittwald.de/docs/v2/platform/aihosting/
- **API Access Guide**: https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/
- **Terms of Use**: https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/
- **Drupal AI Module**: https://www.drupal.org/project/ai

## Development

### Coding Standards

- Follows WordPress coding standards
- No debugging statements allowed in commits

## Contributing

Contributions are welcome! Areas where help is especially appreciated:

- Adding tests
- Documentation improvements
- Bug fixes and performance improvements

To build an installable development version from source, run the `package` composer script:

```bash
$ composer run package
```

This command will create a ZIP file in the project directory that can be uploaded to a WordPress installation.

## License

GPL-2.0-or-later

## Support

For issues related to:
- **This plugin**: Open an issue in this repository
- **mittwald AI Hosting**: See [mittwald documentation](https://developer.mittwald.de/docs/v2/platform/aihosting/)
- **WordPress AI Experiments Plugin**: See the [project page](https://github.com/WordPress/ai)
