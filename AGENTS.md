# LifeOS product rules

- Build user-facing workflows around the person's task. Keep implementation details out of those workflows unless they help the person make a meaningful choice.
- LifeOS AI must be a dedicated, polished conversational panel, available from the app and a global shortcut. Do not replace it with a provider configuration form.
- Do not show model inventories, provider names, "free model" branding, refresh controls or repetitive consent checkboxes in the normal chat flow. Select currently verified zero-cost models in the background, refresh stale availability and fall back when a model disappears. Never silently use a paid model or bypass a provider quota.
- Typed messages can be sent by the Send action with a short online-processing disclosure. Personal LifeOS records remain private by default. Additional record sharing must be deliberate, and proposed writes must still be reviewed and confirmed.
- Do not download or install AI runtimes, model weights or other large AI packages without the user's explicit authorization. Use the already-installed runtime for this project.
- Preserve the established LifeOS visual language and verify the actual conversation, loading, error, action-review and keyboard/native panel flows before shipping.
