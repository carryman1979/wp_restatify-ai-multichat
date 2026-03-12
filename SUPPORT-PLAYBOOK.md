# Support Playbook

This playbook helps support agents handle website chat conversations consistently.

## Goals

- Respond fast.
- Keep answers clear and actionable.
- Escalate complex cases quickly.
- Use AI as assistant, not final authority.

## Suggested SLAs

- First response: within 10 minutes (business hours).
- Follow-up response: within 30 minutes.
- Escalation to specialist: within 20 minutes after detection.

## Standard Workflow

1. Open conversation from inbox link (email or admin).
2. Read latest user message and short history.
3. Classify request:
   - simple how-to
   - account/billing
   - technical issue
   - incident/outage
4. Send first response.
5. If needed, ask one focused clarification question.
6. Resolve or escalate.
7. Confirm resolution with user.

## Response Templates

### First response

Hello, thanks for your message. I am checking this now and will get back to you shortly.

### Clarification

Thanks. Could you please share the exact page URL and the time when this happened?

### Resolved

Great, this should now be fixed. Please refresh and confirm if it works on your side.

### Escalation

Thanks for the details. I am escalating this to our technical team and will update you as soon as we have progress.

## AI Usage Rules

- AI can draft first-level replies.
- Human agent validates before final instruction for critical topics.
- Do not provide legal, medical, or financial advice.
- Never expose secrets, API keys, or internal credentials.

## Escalation Triggers

Escalate immediately if one of these applies:
- payment failure or legal complaint
- data/privacy concern
- security incident suspicion
- production outage
- repeated failure after two support attempts

## Internal Handover Note Format

- Conversation ID:
- Customer issue summary:
- Steps already attempted:
- Current impact:
- Requested action:

## Quality Checklist

Before sending a reply:
- Is the answer in the user language?
- Is it short and actionable?
- Did we avoid assumptions?
- Did we include next step and owner?
