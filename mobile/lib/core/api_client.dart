import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  final String message;
  final int status;
  ApiException(this.message, this.status);
  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({String? baseUrl}) : baseUrl = baseUrl ?? const String.fromEnvironment('RIHLA_API_BASE_URL', defaultValue: 'http://localhost/rihla/api/v1/index.php');
  final String baseUrl;
  final _storage = const FlutterSecureStorage();
  String? csrf;

  Future<dynamic> request(String route, {String method = 'GET', Map<String, dynamic>? body, Map<String, dynamic>? query, bool auth = false}) async {
    final token = await _storage.read(key: 'rihla_token');
    final headers = <String, String>{'Accept': 'application/json', 'Content-Type': 'application/json'};
    if (token != null) headers['Authorization'] = 'Bearer $token';
    if (csrf != null) headers['X-CSRF-Token'] = csrf!;
    final uri = Uri.parse(baseUrl).replace(queryParameters: {'route': route, ...?query?.map((k, v) => MapEntry(k, '$v'))});
    final request = http.Request(method, uri)..headers.addAll(headers);
    if (body != null) request.body = jsonEncode(body);
    final response = await http.Client().send(request).timeout(const Duration(seconds: 20));
    final raw = await response.stream.bytesToString();
    dynamic json;
    try { json = jsonDecode(raw); } catch (_) { throw ApiException('تعذر فهم استجابة الخادم.', response.statusCode); }
    if (json is Map && json['data'] is Map && json['data']['csrf_token'] != null) csrf = json['data']['csrf_token'].toString();
    if (response.statusCode < 200 || response.statusCode >= 300 || json['success'] != true) {
      throw ApiException((json is Map ? json['message'] : null)?.toString() ?? 'تعذر تنفيذ الطلب.', response.statusCode);
    }
    return json['data'];
  }

  Future<void> saveToken(String token) => _storage.write(key: 'rihla_token', value: token);
  Future<void> clearToken() => _storage.delete(key: 'rihla_token');
  Future<bool> hasToken() async => (await _storage.read(key: 'rihla_token')) != null;
}
