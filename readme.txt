=== Roadmap & Feedback Board for WordPress - SimpleBoards ===
Contributors: rafizsejim
Tags: roadmap, feedback, feature requests, voting, ideas board
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Public roadmap, ideas board, and feature voting plugin for WordPress. Collect customer feedback, prioritize by votes, ship product updates users can see.

== Description ==

= Stop paying a third-party service just to talk to your own users =

If you run a WordPress site, you already pay for hosting and you already have a user base. Paying $50 to $400 a month for a SaaS feedback tool to host your public roadmap on a separate subdomain feels backward.

SimpleBoards puts the same workflow inside your own site. Visitors submit ideas, vote on what they want most, and watch roadmap items move from "Up Next" through "In Progress" to "Released" without ever leaving your brand.

[Live demo and Pro details: simpleboardswp.com](https://simpleboardswp.com/?utm_source=wp_readme&utm_medium=plugin&utm_campaign=hero_link)

= Who it is built for =

* WordPress plugin and theme authors collecting feature requests from real users.
* SaaS founders who want a public roadmap on their main site, not a third-party subdomain.
* Agencies sharing roadmap visibility with clients in a portal they already own.
* Membership and community sites turning member ideas into shipped features.
* Internal product teams running roadmap planning entirely inside the WordPress admin.

= What it actually does =

**A public ideas board.** Visitors submit ideas, including guests with no account required. Each idea gets voting, threaded comments, categories, and statuses. You see real demand instead of guessing.

**A kanban-style public roadmap.** Status columns (call them "Up Next", "In Progress", "Released", or anything you want) show progress so users stop emailing your team for updates.

**Feature voting that drives real decisions.** Sort by most-voted, filter by category or status, and put your roadmap effort where the demand is loudest.

**Editable everything.** Status names, status colors, category labels, the "Share an idea" button text, email notification copy. All editable without code.

**Designed to not break your theme.** The public stylesheet is scoped and uses CSS custom properties, so the board renders consistently whether your site runs Astra, Kadence, GeneratePress, Blocksy, or the default Twenty-Twenty themes.

**One-click LoopedIn migration.** Coming from LoopedIn? Import the CSV with titles, descriptions, statuses, dates, and vote counts preserved. Pick "allow duplicates" or "overwrite by title".

= Why it is different =

* **Lives inside WordPress.** No SaaS subscription, no separate domain, no SSO setup.
* **No external accounts required.** Guests can vote and submit without signing up.
* **Your data stays on your server.** Votes, ideas, comments, and emails live in your database. No third party reads them.
* **GPL licensed and open source.** You own what you install. Modify it, extend it, audit it.

= Free features =

* Public roadmap and ideas board, on the same page or split across two pages.
* Voting and threaded comments on every item.
* Guest submissions without forcing visitors to register.
* Search, filter, and sort on both ideas and roadmap, toggleable per board.
* Custom statuses with color, plus a "Release stage" flag so completed items show "Released" instead of "Due".
* Custom categories with color.
* Moderation tools: bulk-edit, reject, or publish from the WordPress admin.
* LoopedIn CSV importer with vote counts and duplicate-handling.
* Editable email notification templates for new submissions and rejections.
* Editable submission button text to match your brand voice.
* Per-item subscriptions for users who want updates, plus one-click unsubscribe.
* Pretty permalinks and a `[sbir_board product="board-slug"]` shortcode for any page.
* Theme-resilient public CSS so the board stays predictable across themes.

= Pro add-on =

[SimpleBoards Pro](https://simpleboardswp.com/?utm_source=wp_readme&utm_medium=plugin&utm_campaign=pro_section) adds the things teams ask for after their first month of use:

* **Private boards.** Restrict by role so internal planning stays internal.
* **Announcements tab and widget.** Publish a changelog of release notes and product updates with unread badges and dismissible cards.
* **Workflow automations.** Auto-promote ideas to roadmap when a vote threshold is hit. Auto-change status when a checklist finishes. Notify by email when an item goes overdue.
* **Checklist progress on cards.** Track sub-tasks on roadmap items with a visible progress bar.
* **Design controls.** Layout, colors, typography, vote button shape, column width, and three preset themes (Clean Light, Midnight Glass, Aurora Soft) without writing CSS.
* **Saved theme presets.** Build a design once, reuse it across boards.
* **Torin AI assistant.** Analyze incoming ideas by intent, find duplicates, suggest the next action.

= Common use cases =

* Replacing a paid Canny, UserVoice, or LoopedIn subscription with a self-hosted alternative.
* Adding a public roadmap to a WordPress plugin or theme's own site, on a `/roadmap` page.
* Running a "feature requests" portal for a SaaS marketing site.
* Sharing roadmap visibility with agency clients without giving them admin logins to internal tools.
* Collecting community feedback on a membership site or course platform.
* Internal product planning inside the WordPress admin for teams already living in WordPress.

More info, live demo, and support: [simpleboardswp.com](https://simpleboardswp.com/?utm_source=wp_readme&utm_medium=plugin&utm_campaign=footer_link)
Email: contact@simpleboardswp.com

== Installation ==

1. Upload the `simpleboards-roadmap` folder to `/wp-content/plugins/`, or install via **Plugins > Add New > Upload Plugin**.
2. Activate **SimpleBoards** in your Plugins list.
3. Go to **SimpleBoards > Boards** and create your first board.
4. Add `[sbir_board product="your-board-slug"]` to any page or post.
5. Publish the page. Visitors can submit ideas and vote right away.

For step-by-step setup with screenshots, see the [SimpleBoards documentation](https://simpleboardswp.com/?utm_source=wp_readme&utm_medium=plugin&utm_campaign=install_docs).

== Frequently Asked Questions ==

= Is this a self-hosted alternative to Canny, UserVoice, or LoopedIn? =

Yes. The free plugin covers public ideas, voting, comments, and a kanban roadmap. The Pro add-on covers announcements, workflow automations, and design controls that competing SaaS tools charge $30 to $300 a month for. If you need enterprise SSO with multiple identity providers or multi-team workspaces, those SaaS tools still have an edge there. For everyone else, SimpleBoards replaces the subscription cleanly.

= How is this different from a Trello or Notion roadmap embed? =

Trello and Notion are not built for public product feedback. They have no voting, no per-item subscriptions, no guest submissions, and embedded views render like an iframe stitched onto your site. SimpleBoards renders natively inside your theme, supports guest voting, and is fully indexable on your own domain.

= Will it slow down my site? =

The plugin uses prepared SQL with proper indexes, batched meta priming, and an object-cache layer for vote counts. Boards with thousands of items stay responsive. No external HTTP requests happen during page render.

= Will it conflict with my theme? =

The public CSS is scoped and uses CSS custom properties so theme styles cannot override layout or contrast accidentally. The plugin is regularly tested on the default themes (Twenty Twenty-X), Astra, Kadence, GeneratePress, and Blocksy. If you hit a specific theme conflict, email support with the theme name.

= How do I show a board on a page? =

Use the shortcode `[sbir_board product="board-slug"]` and replace `board-slug` with the slug of your board. You can place it on as many pages or posts as you want.

= Can one board show both roadmap and ideas? =

Yes. Each board can show the roadmap tab, the ideas tab, or both. Per-board settings control which view appears by default.

= Can people submit ideas without logging in? =

Yes. Enable **Allow guest submissions** in **SimpleBoards > Settings**. Guests provide a name and email. Their submissions land in the same moderation queue as logged-in submissions.

= Can users filter and sort items? =

Yes. Filter by category or status. Sort by newest, oldest, or most voted. You can show or hide filter and sort controls per board.

= Can I import existing feedback from LoopedIn? =

Yes. The **Settings > Import** screen accepts LoopedIn CSV exports. It preserves titles, descriptions, statuses, dates, and vote counts. Pick whether to allow duplicate titles or overwrite existing items.

= Can I import vote counts manually if my source had no per-user data? =

Yes. The item edit screen has a **Votes** field. Set the total vote count directly. Useful when you have an aggregate number but no individual vote rows.

= Are there email notifications? =

Yes. Admins can be notified of new submissions. Submitters can subscribe to per-item status updates. Email subject lines and bodies are editable in **Settings > Emails**.

= Is there a Pro version? =

Yes. Pro adds private boards, announcements, workflow automations, design controls, checklist progress, and the Torin AI assistant. See [simpleboardswp.com](https://simpleboardswp.com/?utm_source=wp_readme&utm_medium=plugin&utm_campaign=faq_pro) for details and pricing.

= Where do I get support? =

Email contact@simpleboardswp.com or open a thread in the plugin's wordpress.org support forum.

== Screenshots ==

1. Public roadmap board with kanban status columns and vote counts on every card.
2. Ideas board with voting, threaded comments, filter, and sort controls.
3. Drawer view for full item details, discussion, and per-item subscription.
4. Board settings for display, moderation, comments, and per-board controls.
5. CSV import screen for LoopedIn migration with vote-count preservation.
6. Pro design controls for layout, colors, typography, and vote button styling.
7. Pro announcements tab on the public board and the announcements widget.
8. Pro workflow automation rules and Torin AI duplicate detection.

== Changelog ==

= 1.0.0 =

First stable release.

* Public roadmap and ideas board with shortcode embedding.
* Voting, threaded comments, and per-item subscriptions.
* Search, filter, and sort on both ideas and roadmap views.
* Guest submissions and full moderation tools.
* Custom statuses with a "Release stage" flag, custom categories with colors.
* LoopedIn CSV import preserving vote counts, with duplicate handling.
* Manual vote count editor for backfilling imports without per-user data.
* Editable submission button text and email notification templates.
* Theme-resilient public styling tested across major themes.
* Pro features available: private boards, announcements, workflow automations, design controls, checklist progress, Torin AI assistant.

== Upgrade Notice ==

= 1.0.0 =

First stable release of SimpleBoards. Run a public roadmap, ideas board, and feature voting inside WordPress without paying for a separate SaaS subscription.
