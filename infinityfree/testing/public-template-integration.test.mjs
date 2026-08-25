import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve('/home/ubuntu/bus-booking-platform/infinityfree');
const read = (file) => readFileSync(resolve(root, file), 'utf8');

const customer = read('customer.php');
const app = read('assets/js/app.js');
const template = read('assets/js/public-template.js');
const styles = read('assets/css/public-template.css');
const service = read('includes/ReferenceService.php');
const api = read('api/v1/index.php');

assert.match(customer, /assets\/css\/public-template\.css/);
assert.match(customer, /assets\/js\/public-template\.js/);
assert.match(app, /RihlaPublicTemplate\.renderHome/);
assert.match(app, /RihlaPublicTemplate\.renderResults/);
assert.match(app, /encodeURIComponent\(query\.bus_type\)/);

assert.match(template, /function renderTripCard\(ctx, trip\)/);
assert.match(template, /ctx\.chooseTrip\(Number\(button\.dataset\.trip\)/);
assert.match(template, /ctx\.viewTrip\(Number\(button\.dataset\.viewTrip\)\)/);
assert.match(template, /data-carousel/);
assert.match(template, /available_seats/);
assert.match(template, /attendance_time/);
assert.doesNotMatch(template, /tripsData\s*=/);
assert.doesNotMatch(template, /alert\(/);

assert.match(styles, /\.rihla-public \.approved-trip-card/);
assert.match(styles, /@media\(max-width:575px\)/);
assert.match(styles, /\.approved-route-line:after/);

assert.match(service, /b\.bus_type, b\.model_year, b\.seat_count AS total_seats/);
assert.match(service, /AS available_seats/);
assert.match(service, /:bus_type_filter/);
assert.match(service, /:bus_type_value/);
assert.match(api, /\$busType = trim/);
assert.match(api, /searchTrips\(\$originCityId, \$destinationCityId, \$date, \$busType\)/);

console.log('public-template-integration.test.mjs: all assertions passed');
