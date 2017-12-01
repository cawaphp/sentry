# Сáша Sentry
-----

## Warning
Be aware that this package is still in heavy developpement.
Some breaking change will occure. Thank's for your comprehension.

## Features
* Listeners for all php error 
* Send error to a queue in order to be send to sentry server async 

## Installation 

Simply add this configuraiton to your production environnment 

```yaml
listeners:
  byClass:
    Cawa\Error\ErrorEvent:
    - Cawa\Sentry\Listeners\Sentry::receive
    Cawa\Events\TimerEvent:
    - Cawa\Sentry\Listeners\Sentry::onTimerEvent
```

add make sur that you have a configured queue / worker to send the event 


### License

Cawa is licensed under the GPL v3 License - see the `LICENSE` file for details
