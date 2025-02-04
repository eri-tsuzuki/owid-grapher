import { GRAPHER_IS_IN_IFRAME_CLASS } from "../grapher/core/GrapherConstants.js"
import React from "react"

export const IFrameDetector = () => (
    <script
        dangerouslySetInnerHTML={{
            __html: `if (window != window.top) document.documentElement.classList.add('${GRAPHER_IS_IN_IFRAME_CLASS}')`,
        }}
    />
)
