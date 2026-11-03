# Changelog

## 0.1.0
- Provider select: TypeSafe (default), OpenAI-compatible (OpenAI, Azure OpenAI, OpenRouter, Groq, Mistral,
  Ollama, vLLM), Anthropic, Gemini; more via the di `ProviderPool` seam (`Api\ProviderInterface`).
- Per-provider base URL, encrypted API key, model and Test Connection; `dmlab:typesafe:ping --provider`.
- Cache, token budget, retries, concurrency, rate-limit pause and circuit breaker shared by every provider.
- Token budget counts input + output tokens; usage report adds output tokens.
- Ping, usage and Test Connection name the provider (Test Connection warns when it is not the active one);
  estimated cost is shown only for TypeSafe.
- Invalid model output is retried once with a repair note, then reported as an error.
- Admin section renamed to *AI Client*; existing TypeSafe settings keep their paths.

## 0.0.2
- Installs on Magento 2.4.7+ and PHP 8.2–8.5; tests run on PHPUnit 11.5 and 12.5.
- CI tests PHP 8.2–8.5 and no longer reports installs to Packagist.
- composer.json: homepage, support links, merchant keywords.
- README rewritten for merchants: problem and outcome, screenshot, install, requirements.

## 0.0.1
- TypeSafe client: `evaluate()` / parallel `evaluateMany()` over curl_multi; Noul, Choice, Score questions.
- Request cache keyed by canonical JSON of model, state and questions; chunked cron purge.
- Identical requests within one `evaluateMany()` are sent once; duplicates are served as cache hits.
- Usage ledger per month and consumer; monthly token budget checked before sending.
- Retries with `retry-after` and jittered backoff; circuit breaker counting failing rounds, not requests.
- A final 401/403 or rate limit stops the rest of the batch without further HTTP calls; a final rate limit
  also pauses later calls for its `retry-after`.
- Admin config with Test Connection (shows the configured model, warns when disabled) and this month's usage under the token budget,
  shared with consumers via `ConfigInterface`; `dmlab:typesafe:ping`, `dmlab:typesafe:usage`.
- HTTPS-only API base URL.
