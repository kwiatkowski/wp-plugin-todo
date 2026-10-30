# wp-plugin-todo

Zaplecze aplikacji [vue-todo](https://github.com/kwiatkowski/vue-todo): zadania z podziałem na projekty i kategorie,
REST API `todo/v1` i serwowanie aplikacji pod `/todo/` z logowaniem WordPressa.

## Wymagania

- PHP 8.1+, WordPress 6.5+
- bez zależności (bez ACF) – wszystko na typach treści i meta WordPressa

## Instalacja

Must-use plugin, w Bedrocku:

```
git clone https://github.com/kwiatkowski/wp-plugin-todo.git web/app/mu-plugins/wp-plugin-todo
```

(w zwykłym WordPressie: `wp-content/mu-plugins/` + plik ładujący albo jako zwykły plugin w `wp-content/plugins/`).

Aplikację Vue klonuje się do `{web root}/todo` – szczegóły w jej README.

## Dane

| | Typ WordPressa | Pola |
| --- | --- | --- |
| Zadanie | post type `todo_task`, status `private` | tytuł, notatki (treść), `_todo_done`, `_todo_status`, `_todo_completed_at`, `_todo_start` (RRRR-MM-DD, pierwszy dzień zadania na kilka dni), `_todo_due` (RRRR-MM-DD), `_todo_time` (GG:MM), `_todo_duration` (minuty), `_todo_priority`, `_todo_position` (kolejność) |
| Projekt | taksonomia `todo_project` | nazwa, `_todo_color`, `_todo_area` (ID kategorii), `_todo_position` (kolejność w kategorii) |
| Kategoria | taksonomia `todo_area` (tylko jako magazyn – zadania nie są do niej przypisywane) | nazwa, `_todo_position` (kolejność), `_todo_icon` (klucz ikony) |

Kategorie grupują projekty (np. „Praca”, „Dom”). Te dwie powstają przy pierwszym odczycie `/areas`
(opcja `wp_plugin_todo_areas_seeded`) – potem można je zmieniać i usuwać. Usunięcie kategorii zostawia
jej projekty bez kategorii. Kolejność kategorii ustawia użytkownik (`/areas/order`); nowa trafia na koniec,
a kategorie jeszcze nieustawione (`position: null`) są na końcu, alfabetycznie.

Nic nie jest publiczne: typy treści są ukryte na froncie, w panelu widać je w menu *Todo* (podgląd i kopia).
Usunięte zadania trafiają do kosza i można je tam przywrócić.

## REST API (`/wp-json/todo/v1`)

Dostęp: zalogowany użytkownik z uprawnieniem `manage_options` (filtr `wp_plugin_todo/capability`).

| Metoda | Ścieżka | Body |
| --- | --- | --- |
| GET | `/tasks` | – |
| POST | `/tasks` | `{ title, notes?, done?, status?, start?, due?, time?, duration?, priority?, projectId?, position? }` |
| PATCH | `/tasks/{id}` | dowolne z powyższych; `null` czyści `start` / `due` / `time` / `duration` / `projectId` / `position` |
| DELETE | `/tasks/{id}` | – (do kosza) |
| GET | `/projects` | – |
| POST | `/projects` | `{ name, color?, areaId? }` |
| PATCH | `/projects/{id}` | `{ name?, color?, areaId? }`; `null` czyści `areaId` |
| DELETE | `/projects/{id}` | – (zadania przechodzą do Skrzynki) |
| POST | `/projects/{id}/copy` | `{ name?, tasks?: bool }` – kopia pod oryginałem, ta sama kategoria i kolor, nazwa `name` albo „Nazwa (kopia)” (i kopie otwartych zadań); zwraca nowy projekt |
| POST | `/projects/order` | `{ ids: [5, 2, 9] }` – te projekty w tej kolejności (zwykle jednej kategorii); zwraca wszystkie projekty |
| GET | `/areas` | – |
| POST | `/areas` | `{ name, icon? }` |
| PATCH | `/areas/{id}` | `{ name?, icon? }`; `null` czyści `icon` |
| DELETE | `/areas/{id}` | – (projekty zostają bez kategorii) |
| POST | `/areas/{id}/copy` | `{ tasks?: bool }` – „Nazwa (kopia)” pod oryginałem, z kopiami projektów (i ich otwartych zadań); zwraca nową kategorię |
| POST | `/areas/order` | `{ ids: [3, 1, 2] }` – każda kategoria dokładnie raz, w nowej kolejności; zwraca listę |

Zadanie w odpowiedzi:

```json
{
  "id": 28356, "title": "Poprawić <header>", "notes": "", "done": false, "status": "doing", "completedAt": null,
  "due": "2026-10-20", "time": "09:30", "duration": 60, "priority": "high", "projectId": 9, "position": 1760605200.5,
  "createdAt": "2026-10-16T06:30:46+00:00", "updatedAt": "2026-10-16T06:30:46+00:00"
}
```

Projekt: `{ "id": 9, "name": "Strona", "color": "#7a9a3a", "areaId": 3, "position": 0 }` (`areaId` i `position` mogą być `null`),
kategoria: `{ "id": 3, "name": "Praca", "position": 0, "icon": "briefcase" }` (`position` i `icon` mogą być `null`;
`icon` to klucz z zestawu ikon aplikacji, `[a-z0-9-]`).

`priority`: `none | low | medium | high`. `status` (kolumna kanbana): `todo | doing | done` – zawsze zgodny
z `done` (`done` = `status === "done"`). Wysłanie `status` ustawia też `done`, wysłanie samego `done`
ustawia `status` na `done` / `todo`; gdy przyjdą oba, wygrywa `status`. Nowe zadanie ma `todo`.
`time` to godzina rozpoczęcia w dniu `due` (`GG:MM`, 24h), `duration` – czas trwania w minutach (5–1440);
wyczyszczenie `due` czyści też `start`, `time` i `duration`. Zadanie może trwać kilka dni: `start` to pierwszy dzień,
`due` ostatni (`start` musi być ustawiony razem z `due` i nie po nim, inaczej 400; `start` równy `due` zapisuje się jako
zadanie jednodniowe). Zadanie na kilka dni jest całodniowe – `time` i `duration` są wtedy czyszczone. `position` (liczba lub `null`) to ręczna kolejność zadań –
aplikacja wstawia przeniesione zadanie między sąsiadów; zadania bez pozycji sortuje po dacie utworzenia. Tekst jest zapisywany dosłownie (bez wycinania „tagów”) –
nigdy nie jest renderowany jako HTML. `notes` (do 10 000 znaków) to Markdown – aplikacja edytuje go z formatowaniem
(pogrubienie, kursywa, przekreślenie, kod, listy punktowane, numerowane i `- [ ]` do odhaczenia). Błędy mają format WordPressa: `{ code, message, data: { status } }`.

## Aplikacja pod `/todo/`

`AppShell` przechwytuje `/todo/…`: niezalogowanego wysyła na stronę logowania (także zmienioną przez
rename-wp-login), zalogowanemu oddaje `web/index.html` aplikacji z `window.__TODO__` (REST URL, nonce,
użytkownik, wylogowanie). Dodaje też skrót *Todo* w pasku admina.

Własny host: stała (albo zmienna środowiska) `TODO_APP_HOST`, np. `todo.kwiatkowski.co` – subdomena wskazująca na ten
sam WordPress (`public_html/web`). Aplikacja działa wtedy od korzenia tej domeny (REST i nonce z tej samej domeny),
a stare adresy `/todo/…` przekierowują tam kodem 301. Logowanie musi być wspólne dla całej domeny:
`COOKIE_DOMAIN` = `.kwiatkowski.co`. Bez stałej – po staremu pod `/todo/`.

## Filtry

| Filtr | Domyślnie | Do czego |
| --- | --- | --- |
| `wp_plugin_todo/capability` | `manage_options` | kto może używać aplikacji i API |
| `wp_plugin_todo/path` | `todo` | adres aplikacji |
| `wp_plugin_todo/app_index` | `{web root}/todo/web/index.html` | plik index.html zbudowanej aplikacji |
| `wp_plugin_todo/done_retention_days` | `30` | po ilu dniach od ukończenia zadanie trafia do kosza (codzienne zadanie cron `wp_plugin_todo/cleanup_done`); `0` – nigdy |

Z MK Core plugin dokłada swoją kartę do widgetu „Informacje o serwisie”.
