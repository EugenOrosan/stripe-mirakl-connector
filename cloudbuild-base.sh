#!/usr/bin/env bash
cur_project="$(gcloud config get-value project 2>/dev/null)"
if [[ "${cur_project}" != "ecomm-hosting" ]] ; then
    echo "Current project is ${cur_project}. Please switch to 'ecomm-hosting'."
    exit 1
fi
#BRANCH_NAME=$(git rev-parse --abbrev-ref HEAD)
SHORT_SHA="$(git rev-parse --short HEAD)"
substitutions="SHORT_SHA=${SHORT_SHA}"
time gcloud builds submit --substitutions="${substitutions}"
