# Opportunity next action

An open opportunity can explicitly designate one pending Task, Call, or Meeting as its next action. Chatwoot displays this choice on the opportunity card; it does not automatically promote the earliest deadline.

## Deployment

Deploy the CRM changes and run the normal EspoCRM rebuild (`php rebuild.php`) before deploying the Chatwoot frontend. The rebuild adds the nullable `Opportunity.nextActionId` and `nextActionType` fields and registers the routes. Existing opportunities start without a selected action.

## API

- `POST /Opportunity/{id}/nextAction`: select an activity using `activityId` and `activityType`; use `activityId: null` to clear the choice without deleting the activity.
- The same endpoint accepts `createTask: true`, `name`, and an optional `dateEndDate` (`YYYY-MM-DD`). It creates and selects a Task, assigning the opportunity's owner (or the current user) and teams.
- `POST /Opportunity/{id}/nextAction/complete`: complete the selected activity and clear the choice atomically. Completion statuses come from each activity type's metadata.

Both endpoints require `expectedId` and `expectedType`, each nullable, matching the displayed choice. Stale choices and closed opportunities return 409. Opportunity read/edit permission is required; selected activities must belong to that opportunity and be readable and pending. Completion additionally requires activity edit and status-field edit permission. Activity creation and updates use the standard record service, including its validation and hooks.

The reference fields are read-only through ordinary Opportunity writes. Task creation/selection and activity completion/clearing each run within a transaction.

## Product verification

1. On an open opportunity, choose **Set next action**, select an existing activity, and reload. The same activity should remain selected even if another activity has an earlier deadline.
2. Create a task from the picker, with and without a deadline. Confirm its owner, teams, parent opportunity, and date in the native Activities workspace.
3. Complete a task, call, and meeting. Confirm their respective completed statuses, removal from the pending badge, and the prompt to define the next step.
4. Change the selected action from another session before completing the old choice. Confirm a conflict and refresh rather than completing the replacement.
5. Check date-only deadlines around midnight in the CRM user's timezone. A date-only task stays due for its entire local day.
6. Verify that Won/Lost cards do not prompt for a next action, bulk selection disables mutations, and loading/failed activity requests never appear as a confirmed empty list.
7. Check the stacked panel in the narrow sidebar and the side-by-side layout when the card's content container is at least 480px wide.

Actions completed, canceled, deleted, or deferred elsewhere are no longer selectable from the pending list. An existing reference to such an activity displays an unavailable state until the user clears or replaces it.
