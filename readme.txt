=== SubmitDock – Headless Form Backend ===
Contributors: imalinawaz
Donate link: https://www.buymeacoffee.com/bealinawaz
Tags: headless, form backend, contact form, rest api, form submissions
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Headless form backend for Next.js, React, and static sites: receive form submissions via REST API, saved in WordPress and emailed to you.

== Description ==

SubmitDock turns your WordPress site into a form backend. Point any form at your endpoint, from a static site, a headless WordPress front end, a React, Next.js, Vue, or Astro app, a mobile app, or a server, and every submission is saved in WordPress and emailed to you.

There is no form builder to set up. You build the form however you like, and the plugin accepts whatever fields it sends. It works like a hosted form backend service such as Formspree, but your submissions stay on your own WordPress site, with no monthly fee or submission limit. It is a free, self-hosted form backend.

**Why use it**

* **Any fields, no setup:** send name, email, message, or fifty custom fields. Each one is saved and shown with a readable label.
* **JSON or plain HTML:** post JSON with `fetch`, or point a normal HTML form at the endpoint. HTML forms can return visitors to a thank-you page.
* **Several forms, one site:** use `/submit/newsletter` or `/submit/quote` to keep forms apart, then filter submissions by form.
* **Email notifications:** each submission is emailed to you, and replying goes straight to the sender.
* **Works across websites:** allow your front-end domains and browsers can submit from them. Requests from other websites are refused.
* **Spam protection:** a hidden spam trap field, a per-visitor rate limit, and an optional check with Cloudflare Turnstile, hCaptcha, or Google reCAPTCHA (v2 or v3), verified on your server before anything is saved.
* **Akismet and a Spam folder:** with the Akismet plugin connected, suspected spam goes to a Spam folder instead of your inbox, with no email. Mark items Spam or Not spam, and old spam is deleted automatically.
* **API key for server-side code:** submit from a Next.js server action, an API route, or an app backend with an `X-SubmitDock-Key` header, even when only your own websites are allowed.
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

This plugin connects to external services only when you turn them on under Form Submissions → Settings. All are off by default.

**ipwho.is IP geolocation** (`https://ipwho.is/`)
When "Look up the approximate city and country" is enabled under Form Submissions → Settings (it is off by default), the visitor's IP address is sent to ipwho.is when they submit a form, and the returned city, region, and country are saved with the submission.
The service is run by ipwhois.io. Terms of use: https://ipwhois.io/terms. Privacy policy: https://ipwhois.io/privacy.

**Cloudflare Turnstile** (`https://challenges.cloudflare.com/turnstile/v0/siteverify`)
When Cloudflare Turnstile is chosen as the spam check, each submission's Turnstile token, your secret key, and the visitor's IP address are sent to Cloudflare to check the token before the submission is saved.
Terms of use: https://www.cloudflare.com/website-terms/. Privacy policy: https://www.cloudflare.com/privacypolicy/.

**hCaptcha** (`https://api.hcaptcha.com/siteverify`)
When hCaptcha is chosen as the spam check, each submission's hCaptcha token, your secret key and site key, and the visitor's IP address are sent to hCaptcha (Intuition Machines, Inc.) to check the token.
Terms of service: https://www.hcaptcha.com/terms. Privacy policy: https://www.hcaptcha.com/privacy.

**Google reCAPTCHA** (`https://www.google.com/recaptcha/api/siteverify`)
When Google reCAPTCHA is chosen as the spam check, each submission's reCAPTCHA token, your secret key, and the visitor's IP address are sent to Google to check the token.
Terms of service: https://policies.google.com/terms. Privacy policy: https://policies.google.com/privacy.

**Akismet** (through the Akismet Anti-spam plugin)
When "Check each submission with Akismet" is enabled, the submitted fields as text, the sender's name and email, the visitor's IP address, browser, and page, and your site address are sent to Akismet (Automattic) through the Akismet plugin's own connection. Marking a submission Spam or Not spam sends the same data again so Akismet can learn.
Terms of service: https://akismet.com/tos/. Privacy policy: https://automattic.com/privacy/.

Your form's CAPTCHA widget itself is added by you to your own front end; this plugin does not load it. Email notifications are sent through your site's own WordPress mail setup.

== Installation ==

1. Install and activate the plugin.
2. Go to **Form Submissions → Settings**. Check the notification email address and add the websites that will send forms, such as `https://example.com`.
3. Open **Form Submissions → Developer guide** and copy an example into your front end.
4. Send a test submission. It appears under **Form Submissions** and in your email.

== Frequently Asked Questions ==

= How is this different from Contact Form 7 or other form plugins? =

Form builder plugins draw the form on WordPress pages. This plugin has no form builder. It gives you an endpoint for forms that live somewhere else, such as a headless front end or a static site, and accepts any fields without configuration.

= Can I use it with Next.js, React, Vue, Astro, or Gatsby? =

Yes. Send a POST request with a JSON body from the browser, or from a server action or API route with your API key. The developer guide has examples, including a Next.js server action.

= Can a plain HTML form use it without JavaScript? =

Yes. Set the form's action to your endpoint and method to post. Add a hidden `__redirect` field to send visitors to a thank-you page with `?form_status=success` or `?form_status=error`.

= Why do I get a 403 error? =

The request came from a website that is not in the allowed list, or from a server without the API key. Servers send no Origin header, so create an API key under Settings and send it in the `X-SubmitDock-Key` header. For browsers, add your website's origin under Settings, or leave the list empty to accept submissions from anywhere.

= How does it stop spam? =

In layers, all optional except the rate limit:

* A hidden field named `_gotcha` (you can change the name) that people never see. Bots fill it in and are rejected.
* Each visitor is limited to five submissions every 15 minutes by default.
* A CAPTCHA check with Cloudflare Turnstile, hCaptcha, or Google reCAPTCHA. Add the service's widget to your form and enter your secret key under Settings. Submissions without a valid token get a 400 error and are not saved.
* Akismet, if the Akismet plugin is active and connected. Suspected spam goes to the Spam folder without an email.

= Which CAPTCHA should I use? =

Any of the three works. Cloudflare Turnstile is free and usually invisible to visitors. reCAPTCHA v3 also runs invisibly and gives a score; set the minimum score under Settings (Google suggests 0.5). The widget adds its own field (`cf-turnstile-response`, `h-captcha-response`, or `g-recaptcha-response`). From JavaScript, you can also send the token as `__captcha`. The token is checked and never saved.

= What happens if the CAPTCHA service is down? =

The submission is saved to the Spam folder instead of being lost, and no email is sent. Check the Spam folder and move it back with Not spam.

= What is the API key for? =

Server-side code, such as a Next.js server action, has no Origin header and no CAPTCHA widget. With the API key in an `X-SubmitDock-Key` header, it can submit even when only your websites are allowed, and it skips the CAPTCHA and the rate limit. Keep the key on your server, never in browser code. It is shown once when created and stored as a hash. The server can pass its visitor's IP address and browser in `__context` as `ip_address` and `user_agent`.

= Can it accept file uploads? =

Not in this version. Text, numbers, true/false values, lists, and nested objects are supported.

= My site is behind Cloudflare or a proxy. Which IP is saved? =

The connecting IP address. Use the `submitdock_client_ip` filter to read the visitor's address from the header your proxy provides.

= How long is spam kept? =

30 days by default, then it is deleted permanently. Change it under Settings, or enter 0 to keep spam until you delete it.

= Are submissions deleted when I uninstall? =

No, only the settings are removed, so messages are never lost by accident. To delete submissions too, define `SUBMITDOCK_DELETE_DATA` as true in wp-config.php before uninstalling.

= Is the plugin available in my language? =

The plugin is ready for translation, and translations are made by volunteers on WordPress.org. You can help translate it into your language at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/submitdock/). Once a language is 90% translated, WordPress installs the translation automatically through language packs. A translation template, languages/submitdock.pot, is included for tools such as Poedit and Loco Translate.

== Screenshots ==

1. Form Submissions inbox with form name, sender email, website and notification status, plus CSV export and filters.
2. A single submission: the submitted fields and visitor details such as page, referrer, browser and device.
3. Settings: email notifications, allowed websites, spam trap, rate limit, privacy options and response messages.
4. Built-in developer guide with ready-to-copy JavaScript, HTML and cURL examples for your endpoint.
5. Spam protection settings: Cloudflare Turnstile, hCaptcha or Google reCAPTCHA, Akismet, and how long to keep spam.
6. The Spam folder, with Not spam actions to move a submission back to the inbox.

== Changelog ==

= 1.2.1 (2026-11-15) =
* Translations: the "Next.js server action" label in the developer guide is now translatable, and a translation template (languages/submitdock.pot) is included.
* Right-to-left languages: the support box uses logical CSS for Arabic, Hebrew, Urdu and other RTL languages.
* Translations: response messages and the email subject that are left at their default are no longer saved in the language the settings were saved in, so visitors get them in their own language. Customized texts are kept.

= 1.2.0 (2026-11-15) =
* New: optional spam check with Cloudflare Turnstile, hCaptcha, or Google reCAPTCHA v2 and v3, verified on your server. Failed checks are refused and the token is never saved.
* New: Akismet check (with the Akismet plugin) and a Spam folder. Suspected spam is kept without an email, and Spam / Not spam actions teach Akismet.
* New: spam older than 30 days is deleted automatically (adjustable, or off).
* New: API key for server-side submissions in an `X-SubmitDock-Key` header. Fixes server-side code being refused when allowed websites are set.
* New: `submitdock_submission_spam` action.
* New: Developer guide sections for the CAPTCHA widget and a Next.js server action.
* Fixed: the settings page now shows "Settings saved." and validation messages.
* Privacy exports and erasures now include submissions in the Spam folder.

= 1.1.0 =
* Renamed from Headless Form to SubmitDock. The endpoint is now `/wp-json/submitdock/v1/submit`, and settings and submissions are stored under new names. A site already running Headless Form keeps its data in the old plugin.

= 1.0.1 =
* New: a View link for each submission in the list.
* New: the notification status shows which address the email was sent to.
* New: links to support, custom development, and donations on the settings and guide pages.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
Translation and right-to-left language improvements. Nothing to change.

= 1.2.0 =
Adds optional Turnstile, hCaptcha, reCAPTCHA and Akismet spam checks, a Spam folder, and an API key for server-side code. Nothing changes until you turn them on.

= 1.1.0 =
Renamed to SubmitDock with a new endpoint, /wp-json/submitdock/v1/submit. Update your forms to the new address.

= 1.0.1 =
Shows who each notification was sent to and adds a View link to the submissions list.

= 1.0.0 =
Initial release.
