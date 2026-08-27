import 'package:flutter/foundation.dart';
import 'core/api_client.dart';
import 'models.dart';

class AppState extends ChangeNotifier {
  AppState(this.api);
  final ApiClient api;
  AppUser? user;
  bool dark = false, loading = false;
  String? error;
  List<City> cities = [], destinations = [];
  List<Trip> trips = [];

  Future<void> bootstrap() async {
    loading = true; notifyListeners();
    try {
      final me = await api.request('auth/me', auth: true);
      user = AppUser(me['user'] ?? me);
    } catch (_) {}
    try { final data = await api.request('cities'); cities = ((data['items'] ?? []) as List).map((e) => City.fromJson(e)).toList(); } catch (_) {}
    loading = false; notifyListeners();
  }
  Future<void> login(String identifier, String password) async {
    loading = true; error = null; notifyListeners();
    try { final d = await api.request('auth/login', method: 'POST', body: {'identifier': identifier, 'password': password}); if (d['token'] != null) await api.saveToken(d['token']); user = AppUser(d['user'] ?? d); } catch (e) { error = e.toString(); }
    loading = false; notifyListeners();
  }
  Future<void> search(int from, int to, String date) async {
    loading = true; error = null; notifyListeners();
    try { final d = await api.request('trips/search', query: {'origin_city_id': from, 'destination_city_id': to, 'date': date}); trips = ((d['items'] ?? []) as List).map((e) => Trip.fromJson(e)).toList(); } catch (e) { error = e.toString(); }
    loading = false; notifyListeners();
  }
  void toggleTheme() { dark = !dark; notifyListeners(); }
}
