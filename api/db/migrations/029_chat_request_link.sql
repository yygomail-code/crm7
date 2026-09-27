ALTER TABLE chat_messages
  ADD COLUMN request_id INT NULL DEFAULT NULL AFTER thread_id,
  ADD KEY idx_chat_request (request_id);
