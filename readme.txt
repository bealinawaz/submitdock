=== SubmitDock – Headless Form Backend via REST API ===
Contributors: imalinawaz
Donate link: https://www.buymeacoffee.com/bealinawaz
Tags: headless, form backend, contact form, rest api, form submissions
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive form submissions from any website or app through a REST API endpoint. WordPress saves every submission and emails it to you.

== Description ==

SubmitDock turns your WordPress site into a form backend. Point any form at your endpoint, from a static site, a headless WordPress front end, a React, Next.js, Vue, or Astro app, a mobile app, or a server, and every submission is saved in WordPress and emailed to you.

There is no form builder to set up. You build the form however you like, and the plugin accepts whatever fields it sends. It works like a hosted form backend service, but your submissions stay on your own WordPress site, with no monthly fee or submission limit.

**Why use it**

* **Any fields, no setup:** send name, email, message, or fifty custom fields. Each one is saved and shown with a readable label.
* **JSON or plain HTML:** post JSON with `fetch`, or point a normal HTML form at the endpoint. HTML forms can return visitors to a thank-you page.
* **Several forms, one site:** use `/submit/newsletter` or `/submit/quote` to keep forms apart, then filter submissions by form.
* **Email notifications:** each submission is emailed to you, and replying goes straight to the sender.
* **Works across websites:** allow your front-end domains and browsers can submit from them. Requests from other websites are refused.
* **Spam protection:** a hidden spam trap field and a per-visitor rate limit, with no CAPTCHA.
* **Inbox and export:** browse submissions in the dashboard, filter by form, and export everything to CSV.
* **Privacy tools:** submissions are included in WordPress personal data exports and erasures, found by the sender's email address. Saving IP addresses can be turned off.
* **Developer hooks:** validate fields, send submissions to a CRM or Slack, or change the email with filters and actions.

**How it works**

1. Install the plugin. Your endpoint is `https://your-site.com/wp-json/submitdock/v1/submit`.
2. Under **Form Submissions → Settings**, add the websites allowed to send forms.
3. Send your form to the endpoint. **Form Submissions → Developer guide** has copy-ready JavaScript, HTML, and cURL examples using your own URL.

Responses are JSON with a `message` ready to show your visitors. Status 200 means saved and emailed, and 202 means saved but the email failed.

**Custom development**

Need a webhook to your CRM, file uploads, a multi-step form, or a full headless WordPress build? The plugin is built and maintained by Ali Nawaz, a WordPress and full stack developer. [Get in touch for custom work](https://bealinawaz.com/contact-me/?utm_source=wordpress.org&utm_medium=plugin-readme&utm_campaign=submitdock).

== External services ==

This plugin can connect to one external service, and only if you turn it on.

**ipwho.is IP geolocation** (`https://ipwho.is/`)
When "Look up the approximate city and country" is enabled under Form Submissions → Settings (it is off by default), the visitor's IP address is sent to ipwho.is when they submit a form, and the returned city, region, and country are saved with the submission.
The service is run by ipwhois.io. Terms of use: https://ipwhois.io/terms. Privacy policy: https://ipwhois.io/privacy.

Email notifications are sent through your site's own WordPress mail setup.

== Installation ==

1. Install and activate the plugin.
2. Go to **Form Submissions → Settings**. Check the notification email address and add the websites that will send forms, such as `https://example.com`.
3. Open **Form Submissions → Developer guide** and copy an example into your front end.
4. Send a test submission. It appears under **Form Submissions** and in your email.

== Frequently Asked Questions ==

= How is this different from Contact Form 7 or other form plugins? =

Form builder plugins draw the form on WordPress pages. This plugin has no form builder. It gives you an endpoint for forms that live somewhere else, such as a headless front end or a static site, and accepts any fields without configuration.

= Can I use it with Next.js, React, Vue, Astro, or Gatsby? =

Yes. Send a POST request with a JSON body from the browser or from a server action or API route. The developer guide has examples.

= Can a plain HTML form use it without JavaScript? =

Yes. Set the form's action to your endpoint and method to post. Add a hidden `__redirect` field to send visitors to a thank-you page with `?form_status=success` or `?form_status=error`.

= Why do I get a 403 error? =

The request came from a website that is not in the allowed list, or from a server, which sends no Origin header. Add your website's origin under Settings, or leave the list empty to accept submissions from anywhere.

= How does it stop spam? =

Add a hidden field named `_gotcha` (you can change the name) that people never see. Bots fill it in and are rejected. Each visitor is also limited to five submissions every 15 minutes by default.

= Can it accept file uploads? =

Not in this version. Text, numbers, true/false values, lists, and nested objects are supported.

= My site is behind Cloudflare or a proxy. Which IP is saved? =

The connecting IP address. Use the `submitdock_client_ip` filter to read the visitor's address from the header your proxy provides.

= Are submissions deleted when I uninstall? =

No, only the settings are removed, so messages are never lost by accident. To delete submissions too, define `SUBMITDOCK_DELETE_DATA` as true in wp-config.php before uninstalling.

== Changelog ==

= 1.1.0 =
* Renamed from Headless Form to SubmitDock. The endpoint is now `/wp-json/submitdock/v1/submit`, and settings and submissions are stored under new names. A site already running Headless Form keeps its data in the old plugin.

= 1.0.1 =
* New: a View link for each submission in the list.
* New: the notification status shows which address the email was sent to.
* New: links to support, custom development, and donations on the settings and guide pages.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Renamed to SubmitDock with a new endpoint, /wp-json/submitdock/v1/submit. Update your forms to the new address.

= 1.0.1 =
Shows who each notification was sent to and adds a View link to the submissions list.

= 1.0.0 =
Initial release.
