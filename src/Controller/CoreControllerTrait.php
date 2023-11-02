<?php

namespace CodeIT\Controller;

trait CoreControllerTrait
{
    protected function getRequestData()
    {
        $request = $this->getRequest();
        $contentType = $request->getHeader('Content-Type');

        $content = explode('?', $request->getUriString());
        if ($request->isGet() && $queryParams = $request->getQuery()->toArray()) {
            return $queryParams;
        } else {
            $content = $request->getContent();
        }

        if ($contentType && $contentType->getFieldValue() == 'application/json') {
            return json_decode($content, true);
        }

        if ($request->isDelete()) {
            parse_str($content, $requestValues);
            return $requestValues;
        }

        return array_merge_recursive($request->getPost()->toArray(), $request->getFiles()->toArray());
    }
}
