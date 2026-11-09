AfrizoN CRM — Digylog tracking/status synchronization removed

Upload all three files to public_html:
- api.php
- index.html
- digylog-admin.html

What this version does:
- Removes the visible Tracking columns from COMONDES and LIVRAISON.
- Digylog webhooks are acknowledged but never modify CRM data.
- Automatic/manual Digylog tracking reconciliation is disabled server-side.
- Digylog can no longer set Livrée, Retournée, tracking, or dateLiv in CRM.
- Phone matching, historical repair, and tracking import actions are disabled.
- Existing regular CRM/manual delivery-status workflow remains available.
- New order dispatch remains separate; the disabled feature is inbound tracking/status synchronization.
