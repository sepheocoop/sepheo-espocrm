# EspoCRM Contact Self-Service Portal — Build Plan

## Overview

An EspoCRM extension that lets Contacts view and edit their own details via a
magic-link email flow, with no EspoCRM user account required.

---

## Phase 1: Local Development Environment

### 1.1 Install prerequisites

- [ ] Install [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [ ] Install [Node.js](https://nodejs.org/) (v18 or newer)
- [ ] Install [VS Code](https://code.visualstudio.com/) + PHP Intelephense extension

### 1.2 Clone the ext-template

```bash
git clone https://github.com/espocrm/ext-template contact-portal
cd contact-portal
npm install
```

### 1.3 Create docker-compose.yml in the project root

```yaml
version: '3'
services:
  espocrm:
    image: espocrm/espocrm
    ports:
      - '8080:80'
    environment:
      ESPOCRM_DATABASE_HOST: mysql
      ESPOCRM_DATABASE_NAME: espocrm
      ESPOCRM_DATABASE_USER: espocrm
      ESPOCRM_DATABASE_PASSWORD: secret
      ESPOCRM_ADMIN_USERNAME: admin
      ESPOCRM_ADMIN_PASSWORD: adminpass
    volumes:
      - espocrm_data:/var/www/html
      - ./custom:/var/www/html/custom
    depends_on:
      - mysql

  mysql:
    image: mysql:8
    environment:
      MYSQL_DATABASE: espocrm
      MYSQL_USER: espocrm
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: rootsecret
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  espocrm_data:
  mysql_data:
```

### 1.4 Start and configure EspoCRM

- [ ] Run `docker compose up -d`
- [ ] Open `http://localhost:8080` and complete the installer
- [ ] Go to Admin → Developer Mode → enable it
- [ ] Create a few test Contact records with email addresses you can receive mail on
- [ ] Configure an outbound email account under Admin → Emails so the magic link
      emails actually send (use something like Mailtrap for local testing)

---

## Phase 2: Extension Scaffold

### 2.1 Update manifest.json

Edit the `manifest.json` that comes with ext-template:

```json
{
  "name": "Contact Portal",
  "version": "1.0.0",
  "acceptableVersions": [">=8.0.0"],
  "php": [">=8.1"],
  "releaseDate": "2026-04-16",
  "author": "Your Name",
  "description": "Allows Contacts to view and edit their own details via a magic link."
}
```

### 2.2 Decide which Contact fields to expose

Before writing any code, list the exact fields contacts should be able to edit.
Example:

- First Name / Last Name
- Email Address
- Phone Number
- Address (Street, City, Country)
- Any custom fields from your signup form (job title, organisation, etc.)

This list drives the HTML form and the save logic.

---

## Phase 3: Add Token Fields to the Contact Entity

### 3.1 Create the entityDefs metadata file

`files/custom/Espo/Modules/ContactPortal/Resources/metadata/entityDefs/Contact.json`

```json
{
  "fields": {
    "portalToken": {
      "type": "varchar",
      "maxLength": 128,
      "notStorable": false
    },
    "portalTokenExpiry": {
      "type": "datetime"
    }
  }
}
```

### 3.2 Apply the schema change

- [ ] Run `node build --copy` to push files into the local instance
- [ ] Go to Admin → Rebuild in EspoCRM to apply the new DB columns
- [ ] Verify the columns exist (optional: check via Admin → Entity Manager → Contact)

---

## Phase 4: The Three Entry Points

Each entry point is a PHP class in:
`files/custom/Espo/Modules/ContactPortal/EntryPoints/`

All three use the `NoAuth` trait so they are publicly accessible.

---

### 4.1 ContactPortalRequest — "Enter your email" page

**URL:** `http://localhost:8080?entryPoint=contactPortalRequest`

**GET request:** renders a simple HTML form asking for an email address.

**POST request:**

1. Read the submitted email
2. Look up the Contact by email using the ORM
3. If not found → show a generic message (don't confirm whether the email exists)
4. If found:
   - Generate a secure token: `bin2hex(random_bytes(32))`
   - Set expiry: 24 hours from now
   - Save `portalToken` and `portalTokenExpiry` on the Contact
   - Send an email to the Contact containing the magic link
   - Show: "If that email is registered, you'll receive a link shortly."

> Security note: always show the same message whether the email was found or not.
> This prevents email enumeration attacks.

---

### 4.2 ContactPortalEdit — The edit form

**URL:** `http://localhost:8080?entryPoint=contactPortalEdit&token=XXXX`

**GET request:**

1. Read `token` from the query string
2. Query for a Contact where `portalToken = token` AND `portalTokenExpiry > now()`
3. If invalid/expired → show error with a link back to the request page
4. If valid → render an HTML form pre-filled with the Contact's current field values
   - Embed the token as a hidden input field
   - Only show the fields you decided on in Phase 2

---

### 4.3 ContactPortalSave — Processes the form submission

**URL:** (same host, POST target from the edit form)

**POST request:**

1. Read the `token` hidden field from the POST body
2. Validate the token again (same check as above — always re-validate)
3. Sanitise and validate each field value (required fields, max lengths, email format)
4. Update the Contact entity fields
5. Save the Contact
6. **Delete the token** (set `portalToken` to null, `portalTokenExpiry` to null) — one-time use
7. Show a success confirmation page

---

## Phase 5: Email Template

### 5.1 Option A — Use EspoCRM's email system (recommended)

Create an Email Template in EspoCRM (Admin → Email Templates) named `Contact Portal Magic Link`.

In the PHP code, use EspoCRM's `EmailSender` service to send it, passing the token URL as a variable.

### 5.2 Option B — Plain PHP mail as a fallback

Compose the email body directly in PHP. Less elegant but has no dependencies.

---

## Phase 6: HTML & Styling

The entry points return plain HTML strings. You control the entire look.

### 6.1 Approach options

- **Inline HTML in PHP** — simplest, no build step, fine for a basic form
- **Separate template files** — load `.html` files from the extension's `Resources/` folder, use `str_replace` for variable injection
- **Tailwind CSS via CDN** — add `<link>` to Tailwind's CDN in the HTML head for quick clean styling with no build step

### 6.2 Pages to build

- [ ] Email request form (one input + submit button)
- [ ] "Check your email" confirmation page
- [ ] Edit form (all the fields from your Phase 2 list)
- [ ] Success page ("Your details have been updated")
- [ ] Error page ("Link expired — request a new one" with a button back to the request form)

---

## Phase 7: Security Checklist

Before deploying, verify each of these:

- [ ] Token is generated with `random_bytes(32)` — not `rand()` or `uniqid()`
- [ ] Token is validated on **every** request, including the save step
- [ ] Token expires (24 hours is reasonable, adjust to taste)
- [ ] Token is deleted immediately after a successful save
- [ ] Contact ID is **never** in the URL — only the token
- [ ] All POST input is sanitised before being saved to the entity
- [ ] The "enter email" page shows the same response whether the email exists or not
- [ ] HTTPS is enforced on production (Cloudron handles this automatically)
- [ ] Rate limiting on the request endpoint — consider a simple cooldown (e.g. don't
      let the same email trigger a new token if one was issued in the last 5 minutes)
      by checking `portalTokenExpiry` before overwriting it

---

## Phase 8: Testing

### 8.1 Happy path

- [ ] Request a link for an existing Contact email → receive email → click link → edit fields → save → verify changes in EspoCRM admin
- [ ] Confirm the token fields are null after a successful save

### 8.2 Edge cases

- [ ] Email not found → generic message shown, no crash
- [ ] Expired token → error page shown
- [ ] Token reuse after save → error page shown
- [ ] Invalid/missing token → error page shown
- [ ] Required field left blank on edit form → validation error shown

---

## Phase 9: Package and Deploy

### 9.1 Build the installable zip

```bash
node build --all
# produces: build/contact-portal-1.0.0.zip
```

### 9.2 Deploy to Cloudron

1. Log into your Cloudron EspoCRM
2. Admin → Extensions → Upload Package
3. Select the `.zip` → Install
4. Admin → Rebuild (to apply any DB schema changes)
5. Test the live URLs

### 9.3 For future updates

1. Make changes locally, test
2. Bump `version` in `manifest.json`
3. Run `node build --all`
4. Upload the new `.zip` via Admin → Extensions → the existing extension → Upgrade

---

## Reference URLs (keep these open while building)

| Topic                         | URL                                                        |
| ----------------------------- | ---------------------------------------------------------- |
| Entry Points                  | https://docs.espocrm.com/development/entry-points/         |
| Extension Packages            | https://docs.espocrm.com/development/extension-packages/   |
| ORM (reading/writing records) | https://docs.espocrm.com/development/orm/                  |
| Email Sending                 | https://docs.espocrm.com/development/email-sending/        |
| Entity Defs metadata          | https://docs.espocrm.com/development/metadata/entity-defs/ |
| ext-template repo             | https://github.com/espocrm/ext-template                    |
| API overview                  | https://docs.espocrm.com/development/api/                  |
