<?php

namespace Bydn\SoloSearch\Model;

class FeedPathValidator
{
    /**
     * Returns whether $path is safe to use as the feed file path relative to the Magento root:
     * not absolute (no leading "/", "\" or Windows drive letter) and without any ".." path
     * traversal segment. Assumes $path is a non-empty string - callers should treat an empty
     * value as "not configured" before calling this.
     *
     * @param string $path
     * @return bool
     */
    public function isValid($path)
    {
        if (strpos($path, '/') === 0 || strpos($path, '\\') === 0) {
            return false;
        }

        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return false;
        }

        $segments = preg_split('/[\/\\\\]+/', $path);

        return !in_array('..', $segments, true);
    }
}
