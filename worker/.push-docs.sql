INSERT INTO documents (source_prefix, path, format, title, excerpt, status, date, updated_at) VALUES
  ('plugin','index.md','md','The WordPress plugin','CF Press does not need WordPress. The plugin exists for one situation: you already run a WordPress site and want it served from Cloudflare without giving up the editor you know.','published','2026-10-29','2026-10-29'),
  ('plugin','install.md','md','Installing the plugin','Three things have to agree before the first export: the Worker URL, the auth key, and which site is the source of truth.','published','2026-10-29','2026-10-29'),
  ('plugin','overview.md','md','The WordPress plugin','CF Press does not need WordPress. The plugin exists for one situation: you already run a WordPress site and want it served from Cloudflare without giving up the editor you know.','published','2026-10-29','2026-10-29'),
  ('plugin','sync-rules.md','md','Sync rules','Two systems holding the same content need a rule for every conflict. These are the ones worth knowing before you rely on the plugin.','published','2026-10-29','2026-10-29')
ON CONFLICT(source_prefix, path) DO UPDATE SET
  format = excluded.format, title = excluded.title, excerpt = excluded.excerpt,
  status = excluded.status, updated_at = excluded.updated_at;
