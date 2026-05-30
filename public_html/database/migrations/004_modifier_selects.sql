-- Add support for select and multi-select modifiers.

ALTER TABLE event_modifiers
  ADD COLUMN options_json TEXT NULL AFTER sort_order,
  ADD COLUMN min_selected INT UNSIGNED NULL AFTER options_json,
  ADD COLUMN max_selected INT UNSIGNED NULL AFTER min_selected;

