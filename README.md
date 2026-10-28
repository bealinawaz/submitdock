# SubmitDock

A self-hosted form backend for WordPress. Send any form from a static site, a headless front end (React, Next.js, Vue, Astro, Gatsby), a mobile app, or a server to a REST API endpoint. Every submission is saved in WordPress and emailed to you.

![Tested up to WordPress 7.1](https://img.shields.io/badge/WordPress-7.1-blue)
![PHP 7.4 to 8.5](https://img.shields.io/badge/PHP-7.4%E2%80%938.5-777bb4)
![License GPLv2 or later](https://img.shields.io/badge/license-GPLv2%2B-green)

No form builder and no field setup: build the form however you like, and the endpoint accepts whatever fields it sends. Think Formspree or Netlify Forms, running on your own WordPress site with no submission limits.

## Features

- **Any fields:** text, numbers, booleans, arrays for checkbox groups, and nested objects. Up to 50 fields per submission.
- **JSON or HTML form posts:** `fetch` with JSON, or a plain `<form action="…" method="post">` that redirects back to a thank-you page.
- **Named forms:** `/submit/newsletter`, `/submit/quote`, and so on, filterable in the dashboard.
- **Email notifications** with Reply-To set to the sender.
- **Allowed websites:** CORS and an origin check restrict which websites can submit.
- **Spam protection:** a configurable honeypot field and a per-IP rate limit.
- **Dashboard inbox** with CSV export.
- **Privacy:** WordPress personal data export and erasure by email address, optional IP storage, and optional IP geolocation (off by default).
- **Hooks** to validate fields, forward submissions to a CRM, Slack, or webhook, and customize the email.

## Quick start

1. Install and activate the plugin (upload the folder to `wp-content/plugins`, or download the ZIP from Releases).
2. Go to **Form Submissions → Settings**, set the notification email, and add your front-end origin, for example `https://example.com`.
3. Post a form:

```js
const response = await fetch("https://your-site.com/wp-json/submitdock/v1/submit", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    name: "Jane",
    email: "jane@example.com",
    message: "Hello",
    _gotcha: "", // spam trap: always empty for real visitors
    __context: { page_url: location.href, referrer: document.referrer },
  }),
});
const { message } = await response.json(); // ready to show the visitor
```

Or without JavaScript:

```html
<form action="https://your-site.com/wp-json/submitdock/v1/submit/contact" method="post">
  <input name="name" required>
  <input name="email" type="email" required>
  <textarea name="message" required></textarea>
  <input name="_gotcha" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
  <input type="hidden" name="__redirect" value="https://example.com/thank-you/">
  <button>Send</button>
</form>
```

The **Developer guide** screen in the dashboard shows these examples with your own endpoint URL.

## API

| | |
| --- | --- |
| Endpoint | `POST /wp-json/submitdock/v1/submit` or `/submit/{form}` (`a-z0-9_-`, up to 64 characters) |
| Body | JSON object, `application/x-www-form-urlencoded`, or `multipart/form-data` (no files) |
| Reserved keys | `__context` (page_url, referrer), `__redirect` (HTML forms), and the honeypot field. Keys starting with `__` are never saved. |
| Limits | 50 fields, 20,000 bytes, 5,000 characters per text value, 100 items per array, 5 levels of nesting |

| Status | Meaning |
| --- | --- |
| `200` | Saved and emailed: `{"success": true, "message": "…", "email_status": "sent"}` |
| `202` | Saved, email failed (`email_status: "failed"`). Treat as success. |
| `400` | Invalid data or spam trap filled |
| `403` | Origin not allowed. Server-to-server requests send no Origin, so they need an empty allow-list. |
| `429` | Rate limit reached |

## Hooks

```php
// Reject or modify fields before saving.
add_filter( 'submitdock_fields', function ( $fields, $request ) {
	if ( empty( $fields['email'] ) ) {
		return new WP_Error( 'missing_email', 'Please enter your email.', array( 'status' => 400 ) );
	}
	return $fields;
}, 10, 2 );

// Forward to a webhook after saving.
add_action( 'submitdock_submission_saved', function ( $post_id, $fields, $form, $visitor ) {
	wp_remote_post( 'https://hooks.example.com/forms', array( 'body' => wp_json_encode( $fields ), 'blocking' => false ) );
}, 10, 4 );
```

Also available: `submitdock_email` (change the notification) and `submitdock_client_ip` (read the real IP behind a proxy or CDN).

## Project structure

```
submitdock.php                         Plugin header and bootstrap
includes/class-submitdock-api.php      REST route, validation, rate limit, notification email
includes/class-submitdock-submissions.php  Submission post type, inbox columns, CSV export
includes/class-submitdock-settings.php Settings defaults and validation
includes/class-submitdock-admin.php    Settings screen and developer guide
includes/class-submitdock-privacy.php  Privacy policy text, data export and erasure
assets/                                            Developer guide styles and copy buttons
uninstall.php                                      Removes settings (and submissions if opted in)
readme.txt                                         WordPress.org readme
```

## Custom development

Need file uploads, CRM integration, multi-step forms, or a full headless WordPress build? The plugin is built and maintained by [Ali Nawaz](https://bealinawaz.com/?utm_source=github&utm_medium=readme&utm_campaign=submitdock), a WordPress and full stack developer. [Get in touch for custom work](https://bealinawaz.com/contact-me/?utm_source=github&utm_medium=readme&utm_campaign=submitdock).

## License

GPLv2 or later. See [LICENSE](LICENSE).
