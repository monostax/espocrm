/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('global:views/opportunity/record/kanban-item', ['views/record/kanban-item'], function (Dep) {
    return Dep.extend({
        template: 'global:opportunity/record/kanban-item',

        data: function () {
            const data = Dep.prototype.data.call(this);
            
            const name = this.model.get('name') || 'Opportunity';
            const accountName = this.model.get('accountName');
            const contactName = this.model.get('contactName');
            const amount = this.model.get('amount');
            const amountCurrency = this.model.get('amountCurrency');
            const probability = this.model.get('probability');
            const closeDate = this.model.get('closeDate');
            const assignedUserName = this.model.get('assignedUserName');
            
            const conversations = this.getConversationsData();
            const tasks = this.model.get('_tasks') || [];
            const amountFormatted = this.formatAmount(amount, amountCurrency);
            const closeDateInfo = this.formatCloseDate(closeDate);
            const totalTasks = this.model.get('_tasksTotal') || tasks.length;
            const remainingTasksCount = Math.max(0, totalTasks - tasks.length);
            
            return {
                ...data,
                id: this.model.id,
                name: name,
                accountName: accountName,
                contactName: contactName,
                amount: amount,
                amountFormatted: amountFormatted,
                probability: probability,
                closeDate: closeDate,
                closeDateFormatted: closeDateInfo.formatted,
                closeDateClass: closeDateInfo.cssClass,
                assignedUserName: assignedUserName,
                conversations: conversations,
                hasConversations: conversations.length > 0,
                conversationsLabel: this.translate('ChatwootConversation', 'scopeNamesPlural'),
                tasks: tasks,
                hasTasks: tasks.length > 0,
                tasksTotal: totalTasks,
                hasMoreTasks: remainingTasksCount > 0,
                remainingTasksCount: remainingTasksCount,
                tasksLabel: this.translate('Task', 'scopeNamesPlural'),
                createTaskLabel: this.translate('Create Task', 'labels', 'Task'),
                viewAllTasksLabel: this.translate('View all', 'labels') + ' (' + totalTasks + ')',
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);
            
            const hasTasksLoaded = this.model.get('_tasksLoaded');
            const hasTasks = this.model.get('_tasks');
            
            if (!hasTasksLoaded || !hasTasks) {
                this.loadTasks();
            }
            
            this.listenTo(this.model, 'sync', () => {
                this.model.unset('_tasksLoaded', {silent: true});
            });
        },

        loadTasks: function () {
            if (this.model.get('_tasksLoaded')) {
                return;
            }
            
            this.model.set('_tasksLoaded', true, {silent: true});
            
            Espo.Ajax.getRequest('Task', {
                where: [
                    {
                        type: 'equals',
                        attribute: 'parentId',
                        value: this.model.id
                    },
                    {
                        type: 'equals',
                        attribute: 'parentType',
                        value: 'Opportunity'
                    },
                    {
                        type: 'notIn',
                        attribute: 'status',
                        value: ['Completed', 'Canceled']
                    }
                ],
                select: 'id,name,status,priority,dateEnd',
                orderBy: 'dateEnd',
                order: 'asc',
                maxSize: 5
            }).then((response) => {
                const tasksTotal = response && response.total ? response.total : 0;
                
                if (response && response.list && response.list.length > 0) {
                    const tasks = response.list.map(task => ({
                        id: task.id,
                        name: task.name,
                        status: task.status,
                        priority: task.priority,
                        dateEnd: task.dateEnd,
                        statusLabel: this.getLanguage().translateOption(task.status, 'status', 'Task'),
                        statusStyle: this.getTaskStatusStyle(task.status),
                        priorityStyle: this.getTaskPriorityStyle(task.priority),
                    }));
                    
                    this.model.set('_tasks', tasks, {silent: true});
                    this.model.set('_tasksTotal', tasksTotal, {silent: true});
                    
                    if (this.isRendered() && !this.isBeingRendered?.()) {
                        this.reRender();
                    } else {
                        setTimeout(() => {
                            if (!this.isRemoved && this.isRendered()) {
                                const tasksInDOM = this.$el.find('.opportunity-task').length;
                                if (tasksInDOM === 0 && tasks.length > 0) {
                                    this.injectTasksIntoDOM(tasks);
                                    this.syncToVisualBoard();
                                }
                            }
                        }, 100);
                    }
                } else {
                    this.model.set('_tasks', [], {silent: true});
                    this.model.set('_tasksTotal', 0, {silent: true});
                }
            }).catch(() => {
                this.model.set('_tasks', [], {silent: true});
                this.model.set('_tasksTotal', 0, {silent: true});
            });
        },

        getTaskStatusStyle: function (status) {
            const styleMap = {
                'Not Started': 'task-status-not-started',
                'Started': 'task-status-started',
                'Completed': 'task-status-completed',
                'Canceled': 'task-status-canceled',
                'Deferred': 'task-status-deferred',
            };
            return styleMap[status] || 'task-status-not-started';
        },

        getTaskPriorityStyle: function (priority) {
            const styleMap = {
                'High': 'task-priority-high',
                'Urgent': 'task-priority-urgent',
            };
            return styleMap[priority] || '';
        },

        formatAmount: function (amount, currency) {
            if (!amount && amount !== 0) return null;
            
            currency = currency || this.getConfig().get('defaultCurrency') || 'BRL';
            const decimalMark = this.getConfig().get('decimalMark') || ',';
            const thousandSeparator = this.getConfig().get('thousandSeparator') || '.';
            
            const parts = parseFloat(amount).toFixed(2).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
            
            const formattedNumber = parts.join(decimalMark);
            
            return currency + ' ' + formattedNumber;
        },

        formatCloseDate: function (closeDate) {
            if (!closeDate) {
                return { formatted: null, cssClass: '' };
            }
            
            const dateTime = this.getDateTime();
            const today = moment().startOf('day');
            const date = moment(closeDate);
            
            if (!date.isValid()) {
                return { formatted: null, cssClass: '' };
            }
            
            const diffDays = date.diff(today, 'days');
            
            let cssClass = '';
            if (diffDays < 0) {
                cssClass = 'is-overdue';
            } else if (diffDays <= 7) {
                cssClass = 'is-soon';
            }
            
            const formatted = dateTime.toDisplayDate(closeDate);
            
            return { formatted: formatted, cssClass: cssClass };
        },

        getConversationsData: function () {
            const conversationsIds = this.model.get('chatwootConversationsIds') || [];
            const conversationsNames = this.model.get('chatwootConversationsNames') || {};
            const conversationsColumns = this.model.get('chatwootConversationsColumns') || {};
            
            return conversationsIds.map(id => {
                const status = conversationsColumns[id] ? conversationsColumns[id].status : null;
                const contactName = conversationsColumns[id] ? conversationsColumns[id].contactDisplayName : null;
                return {
                    id: id,
                    name: conversationsNames[id] || contactName || 'Conversation',
                    status: status,
                    statusLabel: status ? this.getLanguage().translateOption(status, 'status', 'ChatwootConversation') : null,
                    statusStyle: this.getConversationStatusStyle(status),
                };
            });
        },

        getConversationStatusStyle: function (status) {
            const styleMap = {
                'open': 'conv-status-open',
                'resolved': 'conv-status-resolved',
                'pending': 'conv-status-pending',
                'snoozed': 'conv-status-snoozed',
            };
            return styleMap[status] || 'conv-status-default';
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            
            const tasksInModel = this.model.get('_tasks') || [];
            const tasksInDOM = this.$el.find('.opportunity-task').length;
            
            if (tasksInModel.length > 0 && tasksInDOM === 0) {
                this.injectTasksIntoDOM(tasksInModel);
            }
            
            this.$el.find('.btn-create-task').on('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.actionCreateTask();
            });
            
            this.$el.find('.opportunity-task-count').on('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.actionViewAllTasks();
            });
            
            this.$el.find('.opportunity-card').on('click', (e) => {
                if ($(e.target).closest('.item-menu-container').length || 
                    $(e.target).closest('.opportunity-conversation').length ||
                    $(e.target).closest('.opportunity-task').length ||
                    $(e.target).closest('.opportunity-task-count').length ||
                    $(e.target).closest('.btn-create-task').length) {
                    return;
                }
                this.actionQuickView();
            });
            
            if (!this.model.get('_tasksLoaded')) {
                this.loadTasks();
            }
            
            this.syncToVisualBoard();
        },
        
        syncToVisualBoard: function () {
            const itemId = this.model.id;
            const $originalItem = this.$el;
            
            if (!$originalItem.length) return;
            
            const $visualItem = $(`.kanban-board .group-column-list-visual .item[data-id="${itemId}"]`);
            
            if ($visualItem.length) {
                const $newContent = $originalItem.find('.opportunity-card').clone(true);
                $visualItem.find('.opportunity-card').replaceWith($newContent);
                
                $visualItem.find('.btn-create-task').off('click').on('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    this.actionCreateTask();
                });
                
                $visualItem.find('.opportunity-task-count').off('click').on('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    this.actionViewAllTasks();
                });
                
                $visualItem.find('.opportunity-card').off('click').on('click', (e) => {
                    if ($(e.target).closest('.item-menu-container').length || 
                        $(e.target).closest('.opportunity-conversation').length ||
                        $(e.target).closest('.opportunity-task').length ||
                        $(e.target).closest('.opportunity-task-count').length ||
                        $(e.target).closest('.btn-create-task').length) {
                        return;
                    }
                    this.actionQuickView();
                });
            }
        },

        actionCreateTask: function () {
            const attributes = {
                parentId: this.model.id,
                parentType: 'Opportunity',
                parentName: this.model.get('name'),
            };
            
            const accountId = this.model.get('accountId');
            const accountName = this.model.get('accountName');
            if (accountId) {
                attributes.accountId = accountId;
                attributes.accountName = accountName;
            }
            
            const contactId = this.model.get('contactId');
            const contactName = this.model.get('contactName');
            if (contactId) {
                attributes.contactId = contactId;
                attributes.contactName = contactName;
            }
            
            const assignedUserId = this.model.get('assignedUserId');
            const assignedUserName = this.model.get('assignedUserName');
            if (assignedUserId) {
                attributes.assignedUserId = assignedUserId;
                attributes.assignedUserName = assignedUserName;
            }
            
            Espo.Ui.notify(' ... ');
            
            this.createView('quickCreateTask', 'views/modals/edit', {
                scope: 'Task',
                attributes: attributes,
                fullFormDisabled: false,
            }, (view) => {
                Espo.Ui.notify(false);
                view.render();
                
                this.listenToOnce(view, 'after:save', (model) => {
                    const savedModel = model && model.id ? model : view.model;
                    
                    if (!savedModel || !savedModel.id) {
                        return;
                    }
                    
                    const newTask = {
                        id: savedModel.id,
                        name: savedModel.get('name') || 'New Task',
                        status: savedModel.get('status') || 'Not Started',
                        priority: savedModel.get('priority') || 'Normal',
                        dateEnd: savedModel.get('dateEnd'),
                        statusLabel: this.getLanguage().translateOption(savedModel.get('status') || 'Not Started', 'status', 'Task'),
                        statusStyle: this.getTaskStatusStyle(savedModel.get('status') || 'Not Started'),
                        priorityStyle: this.getTaskPriorityStyle(savedModel.get('priority') || 'Normal'),
                    };
                    
                    const currentTasks = this.model.get('_tasks') || [];
                    const currentTotal = this.model.get('_tasksTotal') || 0;
                    
                    const newTasks = [newTask, ...currentTasks].slice(0, 5);
                    
                    this.model.set('_tasks', newTasks, {silent: true});
                    this.model.set('_tasksTotal', currentTotal + 1, {silent: true});
                    this.model.set('_tasksLoaded', true, {silent: true});
                    
                    this.addTaskToDOM(newTask);
                    this.syncToVisualBoard();
                });
            });
        },

        injectTasksIntoDOM: function (tasks) {
            const $card = this.$el.find('.opportunity-card');
            if (!$card.length || !tasks.length) return;
            
            let $taskSection = $card.find('.opportunity-related-section').filter(function() {
                return $(this).find('.btn-create-task').length > 0;
            });
            
            const totalTasks = this.model.get('_tasksTotal') || tasks.length;
            const remainingCount = Math.max(0, totalTasks - tasks.length);
            
            let tasksHtml = tasks.map(task => 
                `<a href="#Task/view/${task.id}" class="opportunity-task ${task.statusStyle} ${task.priorityStyle}" title="${task.name}${task.statusLabel ? ' - ' + task.statusLabel : ''}" data-id="${task.id}" onclick="event.stopPropagation();">
                    <span class="opportunity-task-name">${task.name}</span>
                </a>`
            ).join('');
            
            if (remainingCount > 0) {
                tasksHtml += `<span class="opportunity-task-count" title="${this.translate('View all', 'labels')} (${totalTasks})" data-action="viewAllTasks">+${remainingCount}</span>`;
            }
            
            if ($taskSection.length) {
                const $items = $taskSection.find('.opportunity-related-items');
                const $btn = $items.find('.btn-create-task');
                
                if ($taskSection.find('.opportunity-related-header').length === 0) {
                    $taskSection.prepend(`
                        <div class="opportunity-related-header">
                            <i class="fas fa-tasks"></i>
                            <span>${this.translate('Task', 'scopeNamesPlural')}</span>
                        </div>
                    `);
                }
                
                if ($btn.find('i').length > 1) {
                    $btn.html('<i class="fas fa-plus"></i>');
                }
                
                $btn.before(tasksHtml);
            } else {
                const sectionHtml = `
                    <div class="opportunity-related-section">
                        <div class="opportunity-related-header">
                            <i class="fas fa-tasks"></i>
                            <span>${this.translate('Task', 'scopeNamesPlural')}</span>
                        </div>
                        <div class="opportunity-related-items">
                            ${tasksHtml}
                            <button type="button" class="btn-create-related btn-create-task" title="${this.translate('Create Task', 'labels', 'Task')}">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                `;
                
                const $convSection = $card.find('.opportunity-related-section').filter(function() {
                    return $(this).find('.opportunity-conversation').length > 0;
                });
                
                if ($convSection.length) {
                    $convSection.before(sectionHtml);
                } else {
                    const $footer = $card.find('.opportunity-card-footer');
                    if ($footer.length) {
                        $footer.before(sectionHtml);
                    } else {
                        $card.find('.opportunity-card-header').after(sectionHtml);
                    }
                }
            }
        },

        addTaskToDOM: function (task) {
            const $card = this.$el.find('.opportunity-card');
            if (!$card.length) return;
            
            let $taskItems = $card.find('.opportunity-related-items').filter(function() {
                return $(this).find('.opportunity-task, .btn-create-task').length > 0;
            });
            
            if ($taskItems.length === 0) {
                const sectionHtml = `
                    <div class="opportunity-related-section">
                        <div class="opportunity-related-header">
                            <i class="fas fa-tasks"></i>
                            <span>${this.translate('Task', 'scopeNamesPlural')}</span>
                        </div>
                        <div class="opportunity-related-items">
                            <button type="button" class="btn-create-related btn-create-task" title="${this.translate('Create Task', 'labels', 'Task')}">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                `;
                
                const $convSection = $card.find('.opportunity-related-section').filter(function() {
                    return $(this).find('.opportunity-conversation').length > 0;
                });
                
                if ($convSection.length) {
                    $convSection.before(sectionHtml);
                } else {
                    const $footer = $card.find('.opportunity-card-footer');
                    if ($footer.length) {
                        $footer.before(sectionHtml);
                    } else {
                        $card.find('.opportunity-card-header').after(sectionHtml);
                    }
                }
                
                $taskItems = $card.find('.opportunity-related-items').filter(function() {
                    return $(this).find('.btn-create-task').length > 0;
                });
                
                $taskItems.find('.btn-create-task').on('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    this.actionCreateTask();
                });
            }
            
            const $section = $taskItems.closest('.opportunity-related-section');
            if ($section.find('.opportunity-related-header').length === 0) {
                $section.prepend(`
                    <div class="opportunity-related-header">
                        <i class="fas fa-tasks"></i>
                        <span>${this.translate('Task', 'scopeNamesPlural')}</span>
                    </div>
                `);
            }
            
            const $btn = $taskItems.find('.btn-create-task');
            if ($btn.find('i').length > 1) {
                $btn.html('<i class="fas fa-plus"></i>');
            }
            
            const taskHtml = `
                <a href="#Task/view/${task.id}" class="opportunity-task ${task.statusStyle} ${task.priorityStyle}" title="${task.name}${task.statusLabel ? ' - ' + task.statusLabel : ''}" data-id="${task.id}" onclick="event.stopPropagation();">
                    <span class="opportunity-task-name">${task.name}</span>
                </a>
            `;
            
            const $countBadge = $taskItems.find('.opportunity-task-count');
            
            if ($countBadge.length) {
                $countBadge.before(taskHtml);
            } else if ($btn.length) {
                $btn.before(taskHtml);
            } else {
                $taskItems.append(taskHtml);
            }
        },

        actionViewAllTasks: function () {
            const opportunityId = this.model.id;
            const opportunityName = this.model.get('name') || '';
            
            const searchData = {
                textFilter: '',
                primary: 'all',
                bool: {},
                advanced: {
                    parentId: {
                        type: 'equals',
                        value: opportunityId,
                        data: {
                            type: 'is'
                        }
                    },
                    parentType: {
                        type: 'equals',
                        value: 'Opportunity',
                        data: {
                            type: 'is'
                        }
                    }
                }
            };
            
            // Store search data in session storage where EspoCRM expects it
            this.getStorage().set('listSearch', 'Task', searchData);
            
            // Navigate to Task list - it will read the search data from storage
            this.getRouter().navigate('#Task', {trigger: true});
        },

        actionQuickView: function () {
            const viewName = this.getMetadata().get(['clientDefs', this.model.entityType, 'modalViews', 'detail']) 
                || 'views/modals/detail';

            Espo.Ui.notify(' ... ');

            this.createView('modal', viewName, {
                scope: this.model.entityType,
                model: this.model,
                id: this.model.id,
            }, (view) => {
                Espo.Ui.notify(false);
                view.render();

                this.listenToOnce(view, 'after:save', () => {
                    this.model.fetch();
                });
            });
        },
    });
});
