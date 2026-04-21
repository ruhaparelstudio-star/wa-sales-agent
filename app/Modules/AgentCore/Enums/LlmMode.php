<?php

namespace App\Modules\AgentCore\Enums;

enum LlmMode: string
{
    case Classifier = 'classifier';
    case Response = 'response';
    case FollowUp = 'follow_up';
    case Summary = 'summary';
    case Evaluation = 'evaluation';
}
