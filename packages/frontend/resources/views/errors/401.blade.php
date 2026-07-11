@extends('errors::minimal')

@section('title', __('Unauthorized'))
@section('code', '401')
@section('message', __('Unauthorized'))
@section('headline', __('Please sign in to continue'))
@section('description', __('You need to be signed in to view this page.'))
