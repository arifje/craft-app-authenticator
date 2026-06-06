# App Authenticator

App Authenticator is a small Craft CMS plugin that lets a Flutter app authenticate
against Craft users and restore a logged-in Craft session inside a WebView.

The plugin is useful when a mobile app needs to show authenticated Craft pages
without asking the user to log in every time the app opens. It issues a long-lived
app token after a successful Craft login, stores that token server-side, and can
use it later to create a normal Craft user session for the WebView.

## Requirements

This plugin requires Craft CMS 4.16.0 or later, and PHP 8.0.2 or later.

## Installation

Install the plugin in your Craft project with Composer:

```bash
composer require arjan-brinkman/craft-app-authenticator
php craft plugin/install _app-authenticator
```

The install migration creates an `appauthenticator_tokens` table with:

- `userId`: the Craft user that owns the token
- `token`: a unique random token
- `expiresAt`: the token expiry date
- `createdAt`: when the token was issued

Tokens expire after 30 days.

## Goal

The goal of the plugin is to bridge two authentication contexts:

- Native Flutter app authentication state
- Craft's normal cookie-based web session inside a WebView

Flutter stores the app token securely on the device. When the app opens a Craft
page in a WebView, it calls the plugin with the saved token. If the token is
valid, Craft logs in the matching user and sets the usual Craft session cookies
for the WebView.

## How It Works

The plugin registers public site routes under `/_app-authenticator`.

### `POST /_app-authenticator/login`

Authenticates a Craft user with username/email and password.

Request body:

```json
{
  "username": "person@example.com",
  "password": "secret"
}
```

Successful response:

```json
{
  "success": true,
  "token": "generated-token",
  "expiresAt": "2026-07-06 12:00:00",
  "userId": 123
}
```

### `GET /_app-authenticator/create-token`

Creates or reuses a token for the currently logged-in Craft user.

This route is intended for a login flow where the user signs in through a Craft
page in a WebView first. After Craft has created its normal session, the Flutter
app can call this endpoint from that same WebView session to receive an app token.

### `GET /_app-authenticator/validate`

Validates a saved token.

Header:

```http
Authorization: Bearer generated-token
```

Successful response:

```json
{
  "valid": true,
  "userId": 123,
  "expiresAt": "2026-07-06 12:00:00"
}
```

Expired tokens are removed when validation detects them.

### `GET /_app-authenticator/login-with-token`

Restores a Craft session for a WebView.

Query parameters:

```text
/_app-authenticator/login-with-token?userId=123&token=generated-token
```

If the token is valid, Craft logs in the user and returns user data:

```json
{
  "success": true,
  "userId": 123,
  "expiresAt": "2026-07-06 12:00:00",
  "userData": {
    "id": 123,
    "userName": "person",
    "firstName": "Person",
    "lastName": "Example",
    "email": "person@example.com",
    "avatar": "https://example.com/avatar.jpg",
    "darkTheme": false
  }
}
```

### `POST /_app-authenticator/logout`

Deletes the saved app token.

Header:

```http
Authorization: Bearer generated-token
```

## Flutter Implementation

In a Flutter app, keep the token out of normal app preferences. Use secure
storage, for example `flutter_secure_storage`.

```yaml
dependencies:
  flutter_secure_storage: ^9.2.0
  http: ^1.2.0
  webview_flutter: ^4.8.0
```

### 1. Log In And Store The Token

```dart
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

const baseUrl = 'https://example.com';
const secureStorage = FlutterSecureStorage();

Future<void> login(String username, String password) async {
  final response = await http.post(
    Uri.parse('$baseUrl/_app-authenticator/login'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({
      'username': username,
      'password': password,
    }),
  );

  if (response.statusCode != 200) {
    throw Exception('Invalid login');
  }

  final data = jsonDecode(response.body) as Map<String, dynamic>;

  await secureStorage.write(
    key: 'craftUserId',
    value: data['userId'].toString(),
  );
  await secureStorage.write(key: 'craftToken', value: data['token'] as String);
  await secureStorage.write(
    key: 'craftTokenExpiresAt',
    value: data['expiresAt'] as String,
  );
}
```

### 2. Restore The Craft Session Before Loading WebView Content

Call `login-with-token` first. The request must run in the same WebView context
that will load the authenticated Craft pages, so the Craft session cookie is
available to later WebView navigations.

```dart
import 'package:webview_flutter/webview_flutter.dart';

Future<WebViewController> createAuthenticatedWebView() async {
  final userId = await secureStorage.read(key: 'craftUserId');
  final token = await secureStorage.read(key: 'craftToken');

  final controller = WebViewController()
    ..setJavaScriptMode(JavaScriptMode.unrestricted);

  if (userId == null || token == null) {
    await controller.loadRequest(Uri.parse('$baseUrl/login'));
    return controller;
  }

  final restoreUrl = Uri.parse(
    '$baseUrl/_app-authenticator/login-with-token',
  ).replace(queryParameters: {
    'userId': userId,
    'token': token,
  });

  await controller.loadRequest(restoreUrl);

  return controller;
}
```

After the restore request succeeds, navigate the same controller to the protected
Craft page:

```dart
await controller.loadRequest(Uri.parse('$baseUrl/account'));
```

### 3. Validate A Stored Token

Use validation when the app starts or before restoring a WebView session.

```dart
Future<bool> hasValidCraftToken() async {
  final token = await secureStorage.read(key: 'craftToken');

  if (token == null) {
    return false;
  }

  final response = await http.get(
    Uri.parse('$baseUrl/_app-authenticator/validate'),
    headers: {'Authorization': 'Bearer $token'},
  );

  if (response.statusCode != 200) {
    return false;
  }

  final data = jsonDecode(response.body) as Map<String, dynamic>;
  return data['valid'] == true;
}
```

### 4. Log Out

```dart
Future<void> logout() async {
  final token = await secureStorage.read(key: 'craftToken');

  if (token != null) {
    await http.post(
      Uri.parse('$baseUrl/_app-authenticator/logout'),
      headers: {'Authorization': 'Bearer $token'},
    );
  }

  await secureStorage.delete(key: 'craftUserId');
  await secureStorage.delete(key: 'craftToken');
  await secureStorage.delete(key: 'craftTokenExpiresAt');
}
```

## Recommended App Flow

1. User opens the Flutter app.
2. Flutter checks for a saved `craftToken`.
3. Flutter calls `/_app-authenticator/validate`.
4. If valid, Flutter opens the WebView and loads `login-with-token`.
5. Craft sets its normal session cookies in the WebView.
6. Flutter navigates the same WebView to the protected Craft page.
7. If validation fails, Flutter shows the login screen again.

## Security Notes

- Always use HTTPS in production.
- Store the token with secure device storage.
- Treat the token like a password-equivalent secret.
- Do not log tokens in Flutter, Craft logs, analytics, or crash reports.
- Keep token lifetime appropriate for your app's risk profile.
- Consider adding device metadata or token revocation UI if the app will be used
  in higher-risk environments.
