/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import DynamicHandler from 'dynamic-handler';

class ProgramaItemDynamicHandler extends DynamicHandler {

    onChangeProcedimentoId(model, value, o) {
        if (!o.ui || !value) {
            return;
        }

        const procedimentoType = model.get('procedimentoType');

        if (!procedimentoType) {
            return;
        }

        const params = {
            where: [
                {type: 'equals', attribute: 'procedimentoType', value: procedimentoType},
                {type: 'equals', attribute: 'procedimentoId', value: value},
                {type: 'isTrue', attribute: 'ativo'},
                {type: 'before', attribute: 'vigenciaInicio', value: new Date().toISOString().slice(0, 10)},
            ],
            orderBy: 'vigenciaInicio',
            order: 'desc',
            maxSize: 1,
        };

        Espo.Ajax.getRequest('TabelaDePrecos', params).then(response => {
            if (response.list && response.list.length > 0) {
                model.set('valorUnitario', response.list[0].valor);
            }
        });

        if (procedimentoType === 'ProcedimentoInjetavel') {
            Espo.Ajax.getRequest(`${procedimentoType}/${value}`).then(procedure => {
                if (procedure.dosagemPadrao) {
                    model.set('dosagemPorSessao', procedure.dosagemPadrao);
                }
                if (procedure.unidadeDosagemId) {
                    model.set('unidadeDosagemId', procedure.unidadeDosagemId);
                    model.set('unidadeDosagemName', procedure.unidadeDosagemName || '');
                }
            });
        }
    }

    onChangeQuantidade() {
        this._recalcTotal();
    }

    onChangeValorUnitario() {
        this._recalcTotal();
    }

    _recalcTotal() {
        const quantidade = parseInt(this.model.get('quantidade')) || 0;
        const valorUnitario = parseFloat(this.model.get('valorUnitario')) || 0;

        this.model.set('valorTotal', valorUnitario * quantidade);
    }
}

export default ProgramaItemDynamicHandler;
