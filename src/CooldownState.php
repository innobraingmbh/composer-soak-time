<?php

namespace Innobrain\SoakTime;

enum CooldownState
{
    case Configured;

    case Unconfigured;

    case Disabled;
}
