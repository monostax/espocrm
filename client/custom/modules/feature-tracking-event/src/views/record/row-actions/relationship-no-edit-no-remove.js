/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Row-actions view for relationship panels where rows are read-only and
 * must not be deletable.
 *
 * Used for Tracking* relationship panels (TrackingSource.events,
 * TrackingEventType.events, Contact.trackingEvents) — events are
 * immutable, append-only by definition, so Espo must not offer Edit
 * or Remove. Unlink is preserved so admins can still detach a
 * mis-stitched event from a Contact.
 *
 * Copy of the equivalent view in feature-meta-lead-ads — each feature
 * module keeps its own to stay self-contained.
 */

import RelationshipActionsView from 'views/record/row-actions/relationship';

class RelationshipNoEditNoRemoveActionsView extends RelationshipActionsView {

    getActionList() {
        const list = [{
            action: 'quickView',
            label: 'View',
            data: {
                id: this.model.id,
            },
            link: '#' + this.model.entityType + '/view/' + this.model.id,
            groupIndex: 0,
        }];

        if (!this.options.unlinkDisabled) {
            list.push({
                action: 'unlinkRelated',
                label: 'Unlink',
                data: {
                    id: this.model.id,
                },
                groupIndex: 0,
            });
        }

        return list;
    }
}

// noinspection JSUnusedGlobalSymbols
export default RelationshipNoEditNoRemoveActionsView;
