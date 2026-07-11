@extends('errors::minimal')

@section('title', __('Server Error'))
@section('code', '500')
@section('message', __('Server Error'))
@section('headline', __('Something went wrong on our end'))
@section('description', __('We hit an unexpected problem and our team has been notified. Try again later.'))
