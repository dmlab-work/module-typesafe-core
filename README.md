# DmLab_TypeSafeCore

> AI client for DMLab modules: TypeSafe, OpenAI-compatible, Anthropic, Gemini — one connection, cache and token budget shared by every DMLab AI module.

[![CI](https://github.com/dmlab-work/module-typesafe-core/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-typesafe-core/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-typesafe-core/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-typesafe-core) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-typesafe-core)](https://packagist.org/packages/dmlab/module-typesafe-core) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-typesafe-core)](https://packagist.org/packages/dmlab/module-typesafe-core) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-typesafe-core/php)](https://packagist.org/packages/dmlab/module-typesafe-core) [![License](https://img.shields.io/packagist/l/dmlab/module-typesafe-core)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

AI catalog modules need an AI account, and nobody wants a surprise bill. This module gives Magento one place for
your own key — [TypeSafe](https://typesafe.ai), OpenAI (or any OpenAI-compatible endpoint), Anthropic or Google
Gemini — a monthly token cap with this month's usage shown in the admin, and a cache so the same question is never
paid for twice. DMLab AI catalog modules use it to talk to the provider you pick; on its own it changes nothing in
your catalog or storefront.

![AI Client settings](.github/images/config.png)

## Install
```bash
composer require dmlab/module-typesafe-core
bin/magento module:enable DmLab_TypeSafeCore
bin/magento setup:upgrade
```
Then **Stores → Configuration → DMLab → AI Client**: enable, pick the provider, paste its key, *Test Connection*.

```bash
bin/magento dmlab:typesafe:ping [--provider=<code>]  # check a provider (default: the active one)
bin/magento dmlab:typesafe:usage [--month=YYYY-MM]   # requests, cache hits, input/output tokens per module
```

## Requirements
Magento 2.4.7+ · PHP 8.2–8.5 · `ext-curl` · outbound HTTPS to the chosen provider (plain HTTP is allowed for a
self-hosted OpenAI-compatible endpoint).

## Providers
| Code | Default base URL | Default model | Notes |
|---|---|---|---|
| `typesafe` | `https://api.typesafe.ai` | `jev-1.13.0` | Calibrated probabilities; estimated cost shown in the admin |
| `openai_compatible` | `https://api.openai.com/v1` | `gpt-6-luna` | `base_url` covers OpenAI, Azure OpenAI, OpenRouter, Groq, Mistral, Ollama, vLLM; key optional for self-hosted; Azure `api-key` header added automatically; *Send Temperature* (off by default) for models that accept `temperature: 0` |
| `anthropic` | `https://api.anthropic.com` | `claude-haiku-5-5` | |
| `gemini` | `https://generativelanguage.googleapis.com` | `gemini-3.5-flash-lite` | Key sent in a header, never in the URL |

Keys are stored encrypted and kept out of `app/etc/config.php`; you pay the provider directly. LLM providers answer
more slowly than TypeSafe — raise *Timeout* for large requests. Cache, token budget, retries, rate-limit pause and
the circuit breaker apply to every provider.

## Data and privacy
Only the text a consuming module puts into a request is sent, to the active provider only. Request and response
content is never logged.
- **TypeSafe** — US-hosted API; zero data retention on the enterprise plan.
- **OpenAI-compatible** — goes wherever `base_url` points. For EU data residency use an Azure OpenAI resource in an
  EU region or a self-hosted model (Ollama, vLLM).
- **Anthropic**, **Gemini** — the vendor's public API under your account's data terms.

Pairs with [Catalog Enrich](https://github.com/dmlab-work/module-catalog-enrich), which fills empty product
attributes from descriptions.

## Architecture
Consumers ask structured questions about text — yes/no, pick one of N options, score on a scale — through
`DmLab\TypeSafeCore\Api\ClientInterface`, whatever the provider:
```php
$response = $client->evaluate(new Request(
    ['title' => 'Merino wool crew sweater', 'description' => '…'],
    [
        'is_apparel' => new NoulQuestion('Is this product clothing?'),
        'material' => new ChoiceQuestion('Main material', ['wool' => null, 'cotton' => null, 'synthetic' => null]),
    ],
    'my_module' // usage is accounted per consumer
));
$response->getAnswer('is_apparel')->getProbability(); // 0..1
$response->getAnswer('material')->getChoice();        // 'wool', with getConfidence()
```
`evaluateMany()` sends a batch in parallel (curl_multi, bounded by *Concurrency*) and returns a `Response` or a
`TypeSafeException` per key; it never throws. Use `Api\ConfigInterface::isConfigured()` / `getModel()` instead of
your own settings, and `Api\UsageInterface` for the remaining budget (input + output tokens).

**Provider seam.** A provider (`Api\ProviderInterface`) maps one `Request` to one HTTP exchange: build the request,
parse the body, classify errors, list models. The `Client` keeps cache, budget, retries and rate limiting for all of
them. Add one via di:
```xml
<type name="DmLab\TypeSafeCore\Model\Provider\ProviderPool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="my_llm" xsi:type="object">Vendor\Module\Model\MyLlmProvider</item>
        </argument>
    </arguments>
</type>
```
It appears in the *Provider* select automatically. Its settings are read from
`dmlab_typesafe/<code>/{base_url,api_key,model}`: add a `system.xml` group with that id (with `TestConnectionButton`),
`config.xml` defaults and a `TypePool` `sensitive` entry for the key. Return a non-empty `cacheScope()`; the empty
scope is TypeSafe's, whose answers are keyed by question id.

**Answer mapping.** LLM providers get a fixed prompt and a strict JSON schema per question type and return
`{choice|score|answer, confidence}`; confidence becomes the answer probability (Choice/Score: the picked value gets
`confidence`, the rest share `1 − confidence`; Noul: `confidence` for yes, `1 − confidence` for no, 0.5 when not
stated). Output that breaks the schema is retried once with a repair note, then reported as an error. LLM confidence
is self-reported, not calibrated like TypeSafe probabilities, so tune consumer thresholds per provider.

## License
[OSL-3.0](LICENSE)
