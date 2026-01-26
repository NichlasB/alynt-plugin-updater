# Alynt Plugin Updater Settings

## General Settings

- **Check Frequency**: Controls how often the plugin checks GitHub for updates.
- **Cache Duration**: How long GitHub API responses are cached (seconds). Minimum 300, maximum 86400.

## Webhook Configuration

- **Webhook URL**: Copy this URL into your GitHub webhook settings.
- **Secret Key**: Use this for the webhook secret in GitHub. Generate a new secret if needed.

## Status

- **Last Check**: Timestamp of the last full update check.
- **Next Scheduled Check**: Next cron run.
- **Rate Limit Status**: Shows when GitHub API limits reset.
- **Registered Plugins**: List of detected plugins using the GitHub Plugin URI header.

## GitHub Webhook Setup

1. Go to your GitHub repository → Settings → Webhooks → Add webhook.
2. Payload URL: use the webhook URL from settings.
3. Content type: `application/json`.
4. Secret: use the generated secret.
5. SSL verification: enable.
6. Select individual events → Releases.

Webhook is optional. Cron checks will still run if not configured.
