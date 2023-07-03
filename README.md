# WorldPay Driver for Nails Invoice Module

![license](https://img.shields.io/badge/license-MIT-green.svg)
[![CircleCI branch](https://img.shields.io/circleci/project/github/nails/driver-invoice-worldpay.svg)](https://circleci.com/gh/nails/driver-invoice-worldpay)

This is the "WorldPay" driver for the Nails Invoice module, it allows the module to accept payments using the WorldPay payment processor.

# Configuration
WorldPay requires a potentially large number of configurations to be recorded in order to support various currencies, installations, and services.

This driver interfaces with WorldPay using the [Worldwide Payment Gateway XML API](https://developer.worldpay.com/docs/wpg) and uses a configuration object which contains a configuration object per merchant code to be supported. The driver will choose a configuration object at the time of making a charge depending on the currency being checked out, and whether the customer is present or not.

```json
{
    "PRODUCTION": [
        {
            "merchant_code": "string",
            "for_currency": "string",
            "installation_id": "string",
            "customer_present": bool,
            "xml_username": "string",
            "xml_password": "string>"
        }
    ],
    "STAGING": [ ... ],
    "DEVELOPMENT": [ ... ]
}
```

## `ENVIRONMENT`
Each environment has its own set of independent configurations.

## `merchant_code`
You will have one or more merchant codes associated with your account. This is given to you by WorldPay. Each merchant code should only appear once in the configuration array.

## `for_currency`
This is the ISO code for the currency which the mrchant code supports, e.g. GBP, or USD.

## `installation_id`
If required, supply the installation ID for this merchant code. This is only used when using hosted payment pages.

## `customer_present`
If `true`, this configuration will be selected when the customer is present. If `false` it'll be selected when the customer is not present.

> A merchant code will either be in `ECOM` or `RECUR` mode, with the former for customer initiated transactions (customer present), and the latter for merchant initiated transactions (customer not present).

## `xml_username`
The username to use when querying the XML API.

## `xml_password`
The password to use when querying the XML API.


# 3DS Configuration
If you're using the WorldPay 3DS Flex product it will need configured with some values. The configuration is similar to the above in that multiple environments can be defined at once. It takes the following structure:

```json
{
    "PRODUCTION": [
        {
            "issuer": "string",
            "org_unit_id": "string",
            "mac_key": "string"
        }
    ],
    "STAGING": [ ... ],
    "DEVELOPMENT": [ ... ]
}
```
