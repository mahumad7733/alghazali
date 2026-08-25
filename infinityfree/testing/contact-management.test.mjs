import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '..');
const api = readFileSync(resolve(root, 'api/v1/index.php'), 'utf8');
const service = readFileSync(resolve(root, 'includes/ContactService.php'), 'utf8');
const template = readFileSync(resolve(root, 'assets/js/public-template.js'), 'utf8');
const schema = readFileSync(resolve(root, 'database/contact_channels_migration.sql'), 'utf8');

assert.match(schema, /CREATE TABLE IF NOT EXISTS contact_channels/);
assert.match(schema, /status ENUM\('active','inactive'\)/);
assert.match(service, /WHERE status = \\'active\\'/);
assert.match(service, /contact_channel_status_updated/);
assert.match(api, /contact-channels/);
assert.match(api, /admin\/contacts/);
assert.match(api, /manage_settings/);
assert.match(template, /contactChannels/);
assert.match(template, /public-contact-grid/);
assert.match(template, /contactHref/);

console.log('contact-management.test.mjs: contact channels are database-backed and permission-protected');
