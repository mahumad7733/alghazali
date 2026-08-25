import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
require('../assets/js/trip-sort.js');
const { sortTrips } = globalThis.BusBookingTripSort;

const trips = [
  { id: 'late-low', amount: '12000', departure_at: '2026-08-24 18:00:00' },
  { id: 'early-high', amount: '24000', departure_at: '2026-08-24 06:00:00' },
  { id: 'mid-low', amount: '12000', departure_at: '2026-08-24 12:00:00' },
];

assert.deepEqual(sortTrips(trips, 'departure_asc').map((trip) => trip.id), ['early-high', 'mid-low', 'late-low']);
assert.deepEqual(sortTrips(trips, 'departure_desc').map((trip) => trip.id), ['late-low', 'mid-low', 'early-high']);
assert.deepEqual(sortTrips(trips, 'price_asc').map((trip) => trip.id), ['mid-low', 'late-low', 'early-high']);
assert.deepEqual(sortTrips(trips, 'price_desc').map((trip) => trip.id), ['early-high', 'mid-low', 'late-low']);
assert.deepEqual(trips.map((trip) => trip.id), ['late-low', 'early-high', 'mid-low']);

const customerPage = readFileSync(new URL('../customer.php', import.meta.url), 'utf8');
const appScript = readFileSync(new URL('../assets/js/app.js', import.meta.url), 'utf8');
assert.ok(customerPage.indexOf('assets/js/trip-sort.js') < customerPage.indexOf('assets/js/app.js'));
assert.match(appScript, /id="trip-sort"/);
assert.match(appScript, /BusBookingTripSort\.sortTrips/);

console.log('trip-sort.test.mjs: all assertions passed');
