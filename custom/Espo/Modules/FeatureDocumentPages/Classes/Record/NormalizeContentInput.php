<?php

namespace Espo\Modules\FeatureDocumentPages\Classes\Record;

use Espo\Core\FieldValidation\Exceptions\ValidationError;
use Espo\Core\FieldValidation\Failure;
use Espo\Core\Record\Input\Data;
use Espo\Core\Record\Input\Filter;

/** Keep body-only API updates from reopening the previous editor snapshot. */
class NormalizeContentInput implements Filter
{
    public function filter(Data $data): void
    {
        if ($data->get('bodyEditorState') !== null && !$data->has('body')) {
            throw ValidationError::create(new Failure('Document', 'body', 'required'));
        }

        if ($data->has('body') && !$data->has('bodyEditorState')) {
            $data->set('bodyEditorState', null);
        }
    }
}
