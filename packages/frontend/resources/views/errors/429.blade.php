@extends('errors::minimal')

@section('title', __('Too Many Requests'))
@section('code', '429')
@section('message', __('Too Many Requests'))
@section('headline', __('Slow down a moment'))
@section('description', __('You’ve made a few too many requests. Please wait a little while before trying again.'))
