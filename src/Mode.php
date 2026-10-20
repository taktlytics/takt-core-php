<?php

namespace Vskstudio\Takt;

enum Mode
{
    case Inline;
    case Cdn;
    case Asset;
    // Full SDK render (<script type="module">import{init}…</script>), the only
    // mode able to express scrubUrl (a JS function) and the advanced options.
    case Sdk;
}
