# Security findings

Open **Security → Security** to review findings from built-in checks, advisories and any available analyser. The summary counts open findings by severity. The last finding seen is derived from recorded findings, so a scan with no findings does not update it. The findings table defaults to active findings; use the status, severity and source filters to review other records. Evidence and recommended actions are available on each finding. Analyser findings are advice for a person and are not automatically fixed on their own.

An automatic fix is offered only when a remediation is registered for an eligible finding. Before approving, review the description and whether it is reversible. Approval applies to the weakness, not just one occurrence: Capell may apply the fix automatically during the daily scan until you revoke it. Revocation stops future automatic use; it does not undo changes already made. The approvals list includes revoked approvals, and remediation history records attempts and their before and after details.

Dismiss a finding when you accept its risk. Dismissal does not fix it; the finding stays dismissed while its fingerprint is unchanged. Reopen it to put it back into the active list. A resolved finding is one that a later scan no longer detects; a remediated finding awaits confirmation by a later scan.

People with **View:SecurityPage** can review this page. A person also needs **Manage:SecurityPage** to run scans, approve or revoke fixes, apply approved fixes, dismiss findings or reopen them. Ask an administrator if you need those actions.
