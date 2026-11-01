<?php

namespace App\Concerns;

trait AttachmentValidationRules
{
    /** @return array{attachment: list<string>} */
    protected function attachmentValidationRules(): array
    {
        return [
            'attachment' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,txt,rtf,odt,xls,xlsx,ppt,pptx,csv,jpg,jpeg,png'],
        ];
    }
}
