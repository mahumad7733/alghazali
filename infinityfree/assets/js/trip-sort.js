(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.BusBookingTripSort = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  function departureAt(trip) {
    return Date.parse(String(trip.departure_at || '').replace(' ', 'T')) || 0;
  }

  function price(trip) {
    return Number(trip.amount || 0);
  }

  function sortTrips(trips, sort = 'departure_asc') {
    return [...(trips || [])].sort((first, second) => {
      if (sort === 'price_asc') return price(first) - price(second) || departureAt(first) - departureAt(second);
      if (sort === 'price_desc') return price(second) - price(first) || departureAt(first) - departureAt(second);
      if (sort === 'departure_desc') return departureAt(second) - departureAt(first) || price(first) - price(second);
      return departureAt(first) - departureAt(second) || price(first) - price(second);
    });
  }

  return { sortTrips };
});
