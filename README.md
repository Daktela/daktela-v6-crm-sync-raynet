# Daktela CRM Sync — Raynet Adapter

Raynet CRM adapter for the [daktela/crm-sync](https://github.com/Daktela/daktela-v6-crm-sync-sdk) SDK. Synchronizes contacts, accounts, and activities between Daktela Contact Centre V6 and Raynet CRM.

## How It Works

This package provides:

1. **`RaynetCrmAdapter`** — implements `CrmAdapterInterface` from the SDK, translating between Raynet's nested API responses and the flat entity model the SDK expects.
2. **`RaynetClient`** — a lightweight Guzzle-based HTTP client that handles Raynet's API quirks (reversed PUT/POST, pagination, auth headers).

All sync orchestration, field mapping, transformations, and batch processing are handled by the shared [daktela/crm-sync](https://github.com/Daktela/daktela-v6-crm-sync-sdk) SDK. This package only deals with Raynet-specific concerns.

| Entity     | Direction              | Source of truth |
|------------|------------------------|-----------------|
| Contacts   | Raynet &rarr; Daktela  | Raynet CRM      |
| Accounts   | Raynet &rarr; Daktela  | Raynet CRM      |
| Activities | Daktela &rarr; Raynet  | Daktela CC      |

## Installation

```bash
composer require daktela/crm-sync-raynet
```

This pulls in `daktela/crm-sync` (the SDK), `guzzlehttp/guzzle`, and `psr/log` as dependencies.

## Configuration

### Project Setup

After installing, copy the distribution config files into your project:

```bash
mkdir -p config/mappings
cp vendor/daktela/crm-sync-raynet/config-dist/sync.yaml config/sync.yaml
cp vendor/daktela/crm-sync-raynet/config-dist/mappings/*.yaml config/mappings/
```

Then edit `config/sync.yaml` with your credentials and customize the mapping files.

### sync.yaml

The `raynet` section is specific to this adapter. See the [SDK configuration docs](https://github.com/Daktela/daktela-v6-crm-sync-sdk/blob/main/docs/02-configuration.md) for the `daktela`, `sync`, and `webhook` sections.

```yaml
daktela:
  instance_url: "${DAKTELA_INSTANCE_URL}"
  access_token: "${DAKTELA_ACCESS_TOKEN}"

raynet:
  api_url: "https://app.raynet.cz/api/v2/"
  email: "${RAYNET_EMAIL}"
  api_key: "${RAYNET_API_KEY}"
  instance_name: "${RAYNET_INSTANCE_NAME}"
  person_type: "person"             # "person" or "contact-person"
  owner_id: 0                       # Raynet user ID for activity ownership

sync:
  batch_size: 100
  entities:
    contact:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/contacts.yaml"
    account:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/accounts.yaml"
    activity:
      enabled: true
      direction: cc_to_crm
      mapping_file: "mappings/activities.yaml"
      activity_types: [call, email, web, sms, fbm, wap, vbr]

webhook:
  secret: "${WEBHOOK_SECRET}"
```

Use `${ENV_VAR}` placeholders to keep secrets out of version control. The SDK resolves these at load time via `getenv()`. Inline interpolation works too: `"prefix${VAR}suffix"`. See [SDK env var docs](https://github.com/Daktela/daktela-v6-crm-sync-sdk/blob/main/docs/02-configuration.md#environment-variables).

## Mapping Files

For full field mapping syntax (directions, dot notation, multi-value strategies, relations, transformer chains, custom transformers), see the [SDK field mapping docs](https://github.com/Daktela/daktela-v6-crm-sync-sdk/blob/main/docs/03-field-mapping.md).

Below are the Raynet-specific flattened fields available in mapping files.

### Contacts (`mappings/contacts.yaml`)

The adapter flattens Raynet's nested person response:

| Adapter field  | Raynet API path                    | Description              |
|----------------|------------------------------------|--------------------------|
| `firstName`    | `firstName`                        | First name               |
| `lastName`     | `lastName`                         | Last name                |
| `fullName`     | (computed)                         | First + Last joined      |
| `email`        | `primaryAddress.contactInfo.email` | Primary email            |
| `tel1`         | `primaryAddress.contactInfo.tel1`  | Primary phone            |
| `company_id`   | `company.id`                       | Related company ID       |
| `id`           | `id`                               | Raynet person ID         |

Distribution mapping (`config-dist/mappings/contacts.yaml`):

```yaml
entity: contact
lookup_field: name

mappings:
  - source: title
    target: fullName
    direction: crm_to_cc

  - source: name
    target: id
    direction: crm_to_cc
    transformers:
      - name: prefix
        params: { value: "raynet_person_" }

  - source: firstname
    target: firstName
    direction: crm_to_cc

  - source: lastname
    target: lastName
    direction: crm_to_cc

  - source: customFields.email
    target: email
    direction: crm_to_cc
    transformers:
      - name: wrap_array

  - source: customFields.number
    target: tel1
    direction: crm_to_cc
    transformers:
      - name: wrap_array

  # CRM detail URL — uses env var for instance name
  - source: description
    target: id
    direction: crm_to_cc
    transformers:
      - name: url
        params: { template: "https://app.raynet.cz/${RAYNET_INSTANCE_NAME}/?view=DetailView&en=Person&ei={value}" }

  - source: account
    target: company_id
    direction: crm_to_cc
    relation:
      entity: account
      resolve_from: id
      resolve_to: name
```

### Accounts (`mappings/accounts.yaml`)

| Adapter field  | Raynet API path                    | Description              |
|----------------|------------------------------------|--------------------------|
| `name`         | `name`                             | Company name             |
| `regNumber`    | `regNumber`                        | Registration number      |
| `email`        | `primaryAddress.contactInfo.email` | Company email            |
| `tel1`         | `primaryAddress.contactInfo.tel1`  | Company phone            |
| `id`           | `id`                               | Raynet company ID        |

### Activities (`mappings/activities.yaml`)

Activities flow from Daktela to Raynet. The adapter maps Daktela activity types to Raynet endpoints:

| Daktela type | Raynet endpoint | Description        |
|--------------|-----------------|--------------------|
| `call`       | `phoneCall/`    | Phone call         |
| `email`      | `email/`        | Email              |
| `web`        | `task/`         | Web chat           |
| `sms`        | `task/`         | SMS                |
| `fbm`        | `task/`         | Facebook Messenger |
| `wap`        | `task/`         | WhatsApp           |
| `vbr`        | `task/`         | Viber              |

Raynet activity fields available for mapping:

| Field           | Description                      |
|-----------------|----------------------------------|
| `subject`       | Activity subject/title           |
| `scheduledFrom` | Start time (`Y-m-d H:i` format) |
| `scheduledTill` | End time (`Y-m-d H:i` format)   |
| `externalId`    | External identifier for upsert   |
| `ownerEmail`    | Owner resolution by email        |
| `ownerLogin`    | Owner resolution by login        |

## Raynet API Quirks

### Reversed HTTP Methods

Raynet uses `PUT` for creation and `POST` for updates — the reverse of typical REST conventions. The adapter handles this internally.

### Person Type

Raynet has two endpoints for people:

- **`/person/`** — standalone contacts (not linked to a company)
- **`/contact-person/`** — contacts linked to a company

Set `person_type` in config to choose which endpoint to use. Default is `person`.

### Activity Endpoints

Unlike many CRMs, Raynet has separate endpoints per activity type: `phoneCall/`, `email/`, `task/`, `meeting/`, `event/`, `letter/`. The adapter routes automatically based on the Daktela activity type.

### External IDs

External IDs are stored as `extIds` array, set via `PUT {entity}/{id}/extId/` with `{"extId":"value"}`. Lookup by external ID: `GET {entity}/ext/{extId}/`. The `externalId` field is NOT a filterable column — you must use the `/ext/` endpoint.

### Owner Field on Activities

The `owner` field on activities cannot be sent on POST updates (returns 403). The adapter only sends `owner` on creation.

### Rate Limits

Raynet enforces a daily limit of 24,000 API requests and a maximum of 4 concurrent connections. The adapter throws `RaynetRateLimitException` when the limit is reached (HTTP 429). Use `batch_size` in `sync.yaml` to control how many records are processed per run.

## Examples

Ready-to-use examples in [`examples/`](examples/):

| File | Description |
|------|-------------|
| [`bootstrap.php`](examples/bootstrap.php) | Shared setup — config, both adapters, engine |
| [`full-sync.php`](examples/full-sync.php) | Full sync of all entities |
| [`single-entity-sync.php`](examples/single-entity-sync.php) | Sync entities individually |
| [`single-record-sync.php`](examples/single-record-sync.php) | Sync a single record by ID |
| [`incremental-sync.php`](examples/incremental-sync.php) | Incremental sync with state tracking |
| [`webhook-daktela.php`](examples/webhook-daktela.php) | Daktela webhook endpoint |

## Development

```bash
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
```

## License

Proprietary — requires a valid Daktela Contact Centre license. See [LICENSE](LICENSE) for details.
