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

class OrcamentoDynamicHandler extends DynamicHandler {

    init() {
        this._controlResultadoVisibility();
    }

    onChangeProgramaId(model, value, o) {
        if (!o.ui) {
            return;
        }

        if (!value) {
            return;
        }

        Espo.Ajax.getRequest('Programa/' + value).then(programa => {
            if (!programa) {
                return;
            }

            if (programa.precoTotal != null) {
                model.set('valorTotal', programa.precoTotal);
            }

            if (programa.validadeDias && model.get('dataEmissao')) {
                const emissao = new Date(model.get('dataEmissao'));

                emissao.setDate(emissao.getDate() + programa.validadeDias);

                const yyyy = emissao.getFullYear();
                const mm = String(emissao.getMonth() + 1).padStart(2, '0');
                const dd = String(emissao.getDate()).padStart(2, '0');

                model.set('dataValidade', `${yyyy}-${mm}-${dd}`);
            }
        });
    }

    onChangeValorTotal() {
        this._recalcValorLiquido();
    }

    onChangeValorDesconto() {
        this._recalcValorLiquido();
    }

    onChangeStatus() {
        this._controlResultadoVisibility();
    }

    onChangeJornadaId() {
        this._controlResultadoVisibility();
    }

    onChangeLancamentoId() {
        this._controlResultadoVisibility();
    }

    _recalcValorLiquido() {
        const valorTotal = parseFloat(this.model.get('valorTotal')) || 0;
        const valorDesconto = parseFloat(this.model.get('valorDesconto')) || 0;

        this.model.set('valorLiquido', valorTotal - valorDesconto);
    }

    _controlResultadoVisibility() {
        const jornadaId = this.model.get('jornadaId');
        const lancamentoId = this.model.get('lancamentoId');

        if (jornadaId) {
            this.recordView.showField('jornada');
        } else {
            this.recordView.hideField('jornada');
        }

        if (lancamentoId) {
            this.recordView.showField('lancamento');
        } else {
            this.recordView.hideField('lancamento');
        }
    }
}

export default OrcamentoDynamicHandler;
