<?php

namespace craftquest\featureflags\enums;

enum FlagType: string
{
    case Release = 'release';
    case Experiment = 'experiment';
    case Ops = 'ops';
    case Permission = 'permission';
}
