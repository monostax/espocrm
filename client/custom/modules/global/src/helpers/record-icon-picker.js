import RecordIcon from 'helpers/record-icon';

/** Open one lazy-loaded picker, tied to the lifetime of its owner view. */
async function openPicker(owner, trigger, value, onSelect) {
    if (owner.recordIconPickerOpening) { return; }
    if (owner.recordIconPicker) {
        owner.recordIconPicker.close();
        return;
    }
    owner.recordIconPickerOpening = true;
    let view;
    try {
        view = await owner.createView('recordIconPicker', 'global:views/record-icon-picker', {
            fullSelector: `#record-icon-picker-${owner.cid}`,
            triggerElement: trigger,
            value,
        });
    } finally {
        owner.recordIconPickerOpening = false;
    }
    if (!trigger.isConnected) {
        owner.clearView('recordIconPicker');
        return;
    }
    owner.recordIconPicker = view;
    view.once('remove', () => { owner.recordIconPicker = null; });
    owner.listenToOnce(view, 'close', () => {
        owner.recordIconPicker = null;
        owner.clearView('recordIconPicker');
    });
    owner.listenTo(view, 'select', async icon => {
        try {
            view.setBusy(true);
            await onSelect(icon);
            view.setBusy(false);
            view.close();
        } catch (error) {
            view.setBusy(false);
            view.showError();
        }
    });
    await view.render();
}

function canEditIcon(view) {
    const attribute = RecordIcon.attribute(view, view.model.entityType);
    return attribute && !view.readOnly && !view.disabled &&
        !view.getAcl().getScopeForbiddenFieldList(view.model.entityType, 'edit').includes(attribute) &&
        (view.model.isNew() ? view.getAcl().checkScope(view.model.entityType, 'create') :
            view.getAcl().check(view.model, 'edit'));
}

async function saveIcon(view, icon) {
    const attribute = RecordIcon.attribute(view, view.model.entityType);
    await view.model.save({[attribute]: icon}, {patch: true, wait: true});
    RecordIcon.remember(view, view.model.entityType, view.model.attributes, true);
}

export default {open: openPicker, canEdit: canEditIcon, save: saveIcon};
