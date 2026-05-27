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
 * Used for Meta-managed records (MetaLeadForm questions, leadgen events,
 * leadgen answers, lead-linked contacts/opportunities) — Espo should not
 * offer Edit (the data mirrors Meta and is overwritten on sync) or Remove
 * (would lose audit history). Unlink is preserved so admins can still
 * detach an incorrectly linked lead from a Contact/Opportunity.
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
