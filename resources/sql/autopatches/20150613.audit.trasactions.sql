ALTER TABLE {$NAMESPACE}_audit.audit_transaction ADD INDEX key_report (transactionType, dateCreated);
