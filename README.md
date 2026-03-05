# AccordAI

An AI-mediated conversation platform that helps two or three parties work through conflict in a structured, evidence-based way. No therapist, no lawyer — just a focused conversation guided by AI.

Built with Laravel 12, Vue 3, and Inertia.js. Free to self-host.

---

## How it works

1. **Start a session** — create a chat and set the context (dispute type, topic, etc.)
2. **Invite the other party** — they receive a link to join the conversation
3. **Converse** — each party sends messages; the AI mediator keeps things on track, surfaces key points, and helps both sides feel heard
4. **Finalize** — the session is closed and a summary is generated

---

## Stack

- **Backend** — Laravel 12, SQLite (dev) / MySQL (prod), Laravel Sanctum
- **Frontend** — Vue 3, Inertia.js, Tailwind CSS v4, Reka UI
- **AI** — OpenAI GPT-4o (mediation & reasoning), Anthropic Claude (memory extraction)

---

## Getting started

### Requirements

- PHP 8.2+
- Node.js 20+
- Composer

### Installation

```bash
git clone https://github.com/your-username/accordai.git
cd accordai

composer install
npm install

cp .env.example .env
php artisan key:generate
```

### Environment

Add the following to your `.env`:

```env
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
```

### Run

```bash
php artisan migrate
npm run build
php artisan serve
```

Visit `http://localhost:8000`.

---

## License

MIT
